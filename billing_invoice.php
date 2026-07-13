<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('billing_invoice') && !permission_exists('billing_view')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$start_date = $_GET['start_date'] ?? date('Y-m-01');
	$end_date = $_GET['end_date'] ?? date('Y-m-d');
	$end_date_plus_1 = date('Y-m-d', strtotime($end_date . ' +1 day'));
	$provider_uuid_filter = $_GET['provider_uuid'] ?? '';
	$invoiced_filter = $_GET['invoiced'] ?? 'false';
	$format = $_GET['format'] ?? '';
	$domain_uuid_selected = $_GET['domain_uuid'] ?? $_SESSION['domain_uuid'];
	$caller_id_filter = !empty($_GET['caller_id']) && is_array($_GET['caller_id']) ? $_GET['caller_id'] : [];

	$provider_name = '';
	if (!empty($provider_uuid_filter) && is_uuid($provider_uuid_filter)) {
		$pname = $database->select("select provider_name from v_providers where provider_uuid = :uuid limit 1", ['uuid' => $provider_uuid_filter], 'column');
		if ($pname) $provider_name = $pname;
	}

	if (!empty($_POST['action']) && $_POST['action'] == 'mark_invoiced' && is_array($_POST['selected_ids'])) {
		if (permission_exists('billing_invoice')) {
			foreach ($_POST['selected_ids'] as $id) {
				if (is_uuid($id)) {
					$database->execute("update v_cdr_rated set invoiced = 'true', update_date = now() where cdr_rated_uuid = :uuid", ['uuid' => $id]);
				}
			}
			message::add($text['message-update'], 'positive');
		}
		header("Location: billing_invoice.php?start_date=".urlencode($start_date)."&end_date=".urlencode($end_date)."&provider_uuid=".urlencode($provider_uuid_filter)."&domain_uuid=".urlencode($domain_uuid_selected)."&invoiced=".urlencode($invoiced_filter));
		exit;
	}

	if (empty($format)) {
		$document['title'] = $text['title-billing_invoice'];
		require_once "resources/header.php";

		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".$text['title-billing_invoice']."</b></div>\n";
		echo "	<div class='actions'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing.php']);
		echo "	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
	}

	$sql = "select c.start_stamp, c.caller_id_number, c.destination_number, c.billsec, c.domain_name,
		r.cdr_rated_uuid, r.rate_prefix, r.total_cost, r.billable_seconds, r.rate_cost, r.currency, r.invoiced,
		p.provider_name, ct.call_type_name
		from v_cdr_rated r
		inner join v_xml_cdr c on r.xml_cdr_uuid = c.xml_cdr_uuid
		left join v_providers p on r.provider_uuid = p.provider_uuid
		left join v_provider_rates pr on r.provider_rate_uuid = pr.provider_rate_uuid
		left join v_call_types ct on pr.call_type_uuid = ct.call_type_uuid
		where c.start_stamp >= :start_date
		and c.start_stamp < :end_date
		and c.direction = 'outbound' ";

	$parameters = [
		'start_date' => $start_date,
		'end_date' => $end_date_plus_1,
	];

	if ($invoiced_filter === 'true' || $invoiced_filter === 'false') {
		$sql .= "and r.invoiced = :invoiced ";
		$parameters['invoiced'] = $invoiced_filter;
	}

	if (!empty($provider_uuid_filter) && is_uuid($provider_uuid_filter)) {
		$sql .= "and r.provider_uuid = :provider_uuid ";
		$parameters['provider_uuid'] = $provider_uuid_filter;
	}

	if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$sql .= "and c.domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $domain_uuid_selected;
	} elseif (empty($_GET['domain_uuid']) || !permission_exists('domain_select')) {
		$sql .= "and c.domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}

	if (!empty($caller_id_filter)) {
		$caller_placeholders = [];
		foreach ($caller_id_filter as $i => $cid) {
			$key = 'caller_id_' . $i;
			$caller_placeholders[] = ':' . $key;
			$parameters[$key] = $cid;
		}
		$sql .= "and c.caller_id_number in (" . implode(',', $caller_placeholders) . ") ";
	}

	$sql .= "order by c.start_stamp asc";

	$rows = $database->select($sql, $parameters, 'all');

	// CSV export
	if ($format == 'csv') {
		$filename = "invoice_".$start_date."_to_".$end_date.".csv";
		header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
		header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
		header("Content-Type: application/force-download");
		header("Content-Type: application/octet-stream");
		header("Content-Type: application/download");
		header("Content-Disposition: attachment;filename=".$filename);
		header("Content-Transfer-Encoding: binary");

		$out = fopen("php://output", 'w');
		fputcsv($out, ['Date', 'Caller', 'Destination', 'Provider', 'Call Type', 'Duration (s)', 'Billable (s)', 'Cost (Rp)']);
		$grand_total = 0;
		if (!empty($rows)) {
			foreach ($rows as $row) {
				fputcsv($out, [
					substr($row['start_stamp'], 0, 19),
					$row['caller_id_number'] ?? '',
					$row['destination_number'],
					$row['provider_name'] ?? '-',
					$row['call_type_name'] ?? '-',
					$row['billsec'] ?? 0,
					$row['billable_seconds'] ?? 0,
					number_format($row['total_cost'] ?? 0, 0, ',', '.'),
				]);
				$grand_total += $row['total_cost'] ?? 0;
			}
		}
		fputcsv($out, ['', '', '', '', '', '', 'Total', number_format($grand_total, 0, ',', '.')]);
		fclose($out);
		exit;
	}

	// PDF export
	if ($format == 'pdf') {
		require_once dirname(__DIR__, 2) . "/resources/tcpdf/tcpdf.php";

		$pdf = new TCPDF('P', 'mm', 'A4');
		$pdf->SetAutoPageBreak(true, 20);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(true);
		$pdf->SetMargins(15, 15, 15);
		$pdf->AddPage();

		$pdf->SetFont('helvetica', 'B', 20);
		$pdf->SetTextColor(25, 55, 109);
		$pdf->Cell(0, 10, $_SESSION['domain_name'], 0, 1, 'L');
		$pdf->SetFont('helvetica', '', 12);
		$pdf->SetTextColor(80, 80, 80);
		$pdf->Cell(0, 7, 'LAPORAN TAGIHAN / INVOICE', 0, 1, 'L');
		$pdf->Ln(2);
		$pdf->SetFont('helvetica', '', 9);
		$pdf->Cell(0, 6, 'Periode: '.date('d-m-Y', strtotime($start_date)).' s/d '.date('d-m-Y', strtotime($end_date)), 0, 1, 'L');
		$pdf->Cell(0, 6, 'Tanggal Cetak: '.date('d-m-Y H:i'), 0, 1, 'L');

		if (!empty($provider_name)) {
			$pdf->Cell(0, 6, 'Provider: '.$provider_name, 0, 1, 'L');
		}

		$pdf->Ln(5);

		$html = '<table border="1" cellpadding="4" cellspacing="0" style="font-size:8pt;">';
		$html .= '<thead>';
		$html .= '<tr style="background-color:#19376D; color:#ffffff; font-weight:bold; text-align:center;">';
		$html .= '<th width="6%">No</th>';
		$html .= '<th width="16%">Date</th>';
		$html .= '<th width="10%">Caller</th>';
		$html .= '<th width="18%">Destination</th>';
		$html .= '<th width="14%">Provider</th>';
		$html .= '<th width="12%">Duration</th>';
		$html .= '<th width="12%">Rate</th>';
		$html .= '<th width="12%">Cost</th>';
		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';

		$grand_total = 0;
		$i = 0;
		if (!empty($rows)) {
			foreach ($rows as $row) {
				$i++;
				$grand_total += $row['total_cost'] ?? 0;
				$bg = ($i % 2 == 0) ? 'background-color:#f5f5f5;' : '';
				$html .= '<tr style="'.$bg.'text-align:center;">';
				$html .= '<td>'.$i.'</td>';
				$html .= '<td>'.substr($row['start_stamp'], 0, 16).'</td>';
				$html .= '<td>'.($row['caller_id_number'] ?? '').'</td>';
				$html .= '<td>'.($row['destination_number']).'</td>';
				$html .= '<td>'.($row['provider_name'] ?? '-').'</td>';
				$html .= '<td>'.($row['billsec'] ?? 0).' dtk</td>';
				$html .= '<td>Rp '.number_format($row['rate_cost'] ?? 0, 0, ',', '.').'/mnt</td>';
				$html .= '<td style="text-align:right;">Rp '.number_format($row['total_cost'] ?? 0, 0, ',', '.').'</td>';
				$html .= '</tr>';
			}
		}

		$html .= '<tr style="background-color:#19376D; color:#ffffff; font-weight:bold;">';
		$html .= '<td colspan="7" style="text-align:right;">Total ('.$i.' calls):</td>';
		$html .= '<td style="text-align:right;">Rp '.number_format($grand_total, 0, ',', '.').'</td>';
		$html .= '</tr>';
		$html .= '</tbody>';
		$html .= '</table>';

		$pdf->SetFont('helvetica', '', 8);
		$pdf->writeHTML($html, true, false, true, false, '');

		$pdf->Output('invoice_'.$start_date.'_to_'.$end_date.'.pdf', 'D');
		exit;
	}

	//HTML view
	echo "<form method='get'>\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='15%' class='vncell' valign='middle' align='left' nowrap>".$text['label-start_date']."</td>\n";
	echo "<td width='35%' class='vtable' align='left'><input type='date' class='formfld' name='start_date' value='".escape($start_date)."' style='max-width: 220px;'></td>\n";
	echo "<td width='15%' class='vncell' valign='middle' align='left' nowrap>".$text['label-end_date']."</td>\n";
	echo "<td class='vtable' align='left'><input type='date' class='formfld' name='end_date' value='".escape($end_date)."' style='max-width: 220px;'></td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='middle' align='left' nowrap>".$text['label-invoiced']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='invoiced' style='max-width: 220px;' onchange='this.form.submit()'>\n";
	$inv_opts = ['false' => $text['label-no'], 'true' => $text['label-yes'], '' => '-- All --'];
	foreach ($inv_opts as $val => $label) {
		$sel = ($invoiced_filter == (string)$val) ? "selected='selected'" : '';
		echo "		<option value='".escape($val)."' $sel>".$label."</option>\n";
	}
	echo "	</select>\n";
	echo "</td>\n";
	echo "<td class='vncell'>&nbsp;</td>\n";
	echo "<td>&nbsp;</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='middle' align='left' nowrap>".$text['label-provider']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='provider_uuid' style='max-width: 220px;'>\n";
	echo "		<option value=''>-- All Providers --</option>\n";
	if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$filter_domain = $domain_uuid_selected;
	} else {
		$filter_domain = $_SESSION['domain_uuid'];
	}
	$sql_providers = "select provider_uuid, provider_name from v_providers where provider_enabled = 'true' and domain_uuid = :domain_uuid order by provider_name asc";
	$providers = $database->select($sql_providers, ['domain_uuid' => $filter_domain], 'all');
	if (!empty($providers)) {
		foreach ($providers as $p) {
			$sel = ($p['provider_uuid'] == $provider_uuid_filter) ? "selected='selected'" : '';
			echo "		<option value='".escape($p['provider_uuid'])."' $sel>".escape($p['provider_name'])."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "</td>\n";

	if (permission_exists('domain_select')) {
		echo "<td class='vncell' valign='middle' align='left' nowrap>".$text['label-select_domain']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='domain_uuid'>\n";
		$sql_domains = "select domain_uuid, domain_name from v_domains where domain_enabled = 'true' order by domain_name asc";
		$domains = $database->select($sql_domains, null, 'all');
		if (!empty($domains)) {
			if ($domain_uuid_selected == 'all') {
				echo "		<option value='all' selected='selected'>".$text['label-select_all']."</option>\n";
			} else {
				echo "		<option value='all'>".$text['label-select_all']."</option>\n";
			}
			foreach ($domains as $d) {
				$sel = ($d['domain_uuid'] == $domain_uuid_selected) ? "selected='selected'" : '';
				echo "		<option value='".escape($d['domain_uuid'])."' $sel>".escape($d['domain_name'])."</option>\n";
			}
		}
		echo "	</select>\n";
		echo "</td>\n";
	} else {
		echo "<td class='vncell'>&nbsp;</td>\n";
		echo "<td>&nbsp;</td>\n";
	}

	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-caller']."</td>\n";
	echo "<td class='vtable' align='left' colspan='3'>\n";

	if (!empty($_GET['domain_uuid'])) {
		$caller_domain_uuid = $_GET['domain_uuid'];
	} elseif ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$caller_domain_uuid = $domain_uuid_selected;
	} else {
		$caller_domain_uuid = $_SESSION['domain_uuid'];
	}
	$sql_callers = "select distinct c.caller_id_number from v_cdr_rated r inner join v_xml_cdr c on r.xml_cdr_uuid = c.xml_cdr_uuid where c.caller_id_number is not null and c.caller_id_number != '' ";
	$caller_params = [];
	if ($caller_domain_uuid != 'all' && is_uuid($caller_domain_uuid)) {
		$sql_callers .= "and c.domain_uuid = :domain_uuid ";
		$caller_params['domain_uuid'] = $caller_domain_uuid;
	}
	$sql_callers .= "order by c.caller_id_number asc";
	$callers = $database->select($sql_callers, $caller_params, 'all');

	echo "	<select class='formfld' name='caller_id[]' multiple size='4' style='max-width: 400px; resize: both; min-width: 200px; min-height: 60px;'>\n";
	$sel_all = empty($caller_id_filter) ? "selected='selected'" : '';
	echo "		<option value='' $sel_all>-- All --</option>\n";
	if (!empty($callers)) {
		foreach ($callers as $c) {
			$cid = $c['caller_id_number'];
			$sel = in_array($cid, $caller_id_filter) ? "selected='selected'" : '';
			echo "		<option value='".escape($cid)."' $sel>".escape($cid)."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "	<div style='font-size: 10px; color: #888; margin-top: 2px;'>Hold Ctrl to select multiple extensions.</div>\n";
	echo "</td>\n";
	echo "</tr>\n";

	$export_params = "start_date=".urlencode($start_date)."&end_date=".urlencode($end_date)."&provider_uuid=".urlencode($provider_uuid_filter)."&domain_uuid=".urlencode($domain_uuid_selected);
	if (!empty($caller_id_filter)) {
		foreach ($caller_id_filter as $cid) {
			$export_params .= "&caller_id[]=".urlencode($cid);
		}
	}

	echo "<tr>\n";
	echo "<td class='vncell'>&nbsp;</td>\n";
	echo "<td class='vtable' align='left' colspan='3'>\n";
	echo "	<button type='submit' class='btn' name='search' style='background: #1a73e8; color: #fff; border: none; padding: 6px 16px; border-radius: 3px; cursor: pointer; font-size: 13px;'><i class='fas fa-search'></i> ".$text['label-search']."</button>\n";
	echo "	<a href='?".$export_params."&format=csv' class='btn' style='background: #34a853; color: #fff; border: none; padding: 6px 16px; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px;'><i class='fas fa-file-csv'></i> CSV</a>\n";
	echo "	<a href='?".$export_params."&format=pdf' class='btn' style='background: #ea4335; color: #fff; border: none; padding: 6px 16px; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px;'><i class='fas fa-file-pdf'></i> PDF</a>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	echo "<div class='card'>\n";

	echo "<div style='padding: 0 4px 16px 4px; border-bottom: 2px solid #19376D; margin-bottom: 16px;'>\n";
	echo "	<div style='font-size: 18px; font-weight: bold; color: #19376D;'>".escape($_SESSION['domain_name'])."</div>\n";
	echo "	<div style='font-size: 14px; color: #555; margin-top: 4px;'>".$text['title-billing_invoice']."</div>\n";
	echo "	<div style='font-size: 11px; color: #888; margin-top: 2px;'>Periode: ".date('d-m-Y', strtotime($start_date))." s/d ".date('d-m-Y', strtotime($end_date))."</div>\n";
	if (!empty($provider_name)) {
		echo "	<div style='font-size: 11px; color: #888;'>Provider: ".escape($provider_name)."</div>\n";
	}
	echo "	<div style='font-size: 11px; color: #888;'>Tanggal Cetak: ".date('d-m-Y H:i')."</div>\n";
	echo "</div>\n";

	echo "<form method='post' id='form_invoice'>\n";
	echo "<input type='hidden' name='action' value='mark_invoiced'>\n";
	echo "<input type='hidden' name='start_date' value='".escape($start_date)."'>\n";
	echo "<input type='hidden' name='end_date' value='".escape($end_date)."'>\n";
	echo "<input type='hidden' name='provider_uuid' value='".escape($provider_uuid_filter)."'>\n";
	echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid_selected)."'>\n";
	echo "<input type='hidden' name='invoiced' value='".escape($invoiced_filter)."'>\n";

	echo "<div class='card'>\n";

	if (permission_exists('billing_invoice') && $invoiced_filter != 'true') {
		echo "	<div style='float: right; margin-bottom: 10px;'>\n";
		echo "		<button type='button' id='btn_mark_invoiced' class='btn' style='display: none; background: #34a853; color: #fff; border: none; padding: 6px 16px; border-radius: 3px; cursor: pointer; font-size: 13px;' onclick='confirmMarkInvoiced()'>\n";
		echo "			<i class='fas fa-check-circle'></i> ".$text['button-mark_invoiced']."\n";
		echo "		</button>\n";
		echo "	</div>\n";
	}

	echo "<table class='list' style='width: 100%; border-collapse: collapse;'>\n";
	echo "<tr class='list-header' style='background-color: #19376D; color: #fff;'>\n";
	if (permission_exists('billing_invoice') && $invoiced_filter != 'true') {
		echo "	<th class='checkbox' style='width: 30px;'><input type='checkbox' id='checkbox_all' onclick='toggleAllCheckboxes(this)'></th>\n";
	}
	echo "	<th style='width: 40px;'>No</th>\n";
	echo "	<th>".$text['label-call_date']."</th>\n";
	echo "	<th>".$text['label-caller']."</th>\n";
	echo "	<th>".$text['label-destination_number']."</th>\n";
	echo "	<th>".$text['label-provider']."</th>\n";
	echo "	<th>Rate</th>\n";
	echo "	<th class='center' style='width: 80px;'>".$text['label-duration']."</th>\n";
	echo "	<th class='center' style='width: 100px;'>".$text['label-cost']."</th>\n";
	if (permission_exists('billing_invoice')) {
		echo "	<th class='center' style='width: 60px;'>".$text['label-invoiced']."</th>\n";
	}
	echo "</tr>\n";

	$grand_total = 0;
	$i = 0;

	if (!empty($rows)) {
		foreach ($rows as $row) {
			$i++;
			$grand_total += $row['total_cost'] ?? 0;
			$is_invoiced = ($row['invoiced'] ?? 'false') === true || $row['invoiced'] === 't' || $row['invoiced'] === 'true';
			echo "<tr class='list-row'>\n";
			if (permission_exists('billing_invoice') && $invoiced_filter != 'true') {
				echo "	<td class='checkbox'><input type='checkbox' name='selected_ids[]' value='".escape($row['cdr_rated_uuid'])."' class='row_checkbox' onclick='updateMarkButton()'></td>\n";
			}
			echo "	<td style='text-align: center;'>".$i."</td>\n";
			echo "	<td>".escape(substr($row['start_stamp'], 0, 16))."</td>\n";
			echo "	<td>".escape($row['caller_id_number'] ?? '')."</td>\n";
			echo "	<td>".escape($row['destination_number'])."</td>\n";
			echo "	<td>".escape($row['provider_name'] ?? '-')."</td>\n";
			echo "	<td>Rp ".number_format($row['rate_cost'] ?? 0, 0, ',', '.')."/mnt</td>\n";
			echo "	<td class='center'>".($row['billsec'] ?? 0)." dtk</td>\n";
			echo "	<td class='center'>Rp ".number_format($row['total_cost'] ?? 0, 0, ',', '.')."</td>\n";
			if (permission_exists('billing_invoice')) {
				echo "	<td class='center'>".($is_invoiced ? $text['label-yes'] : $text['label-no'])."</td>\n";
			}
			echo "</tr>\n";
		}
	} else {
		$colspan = 7 + (permission_exists('billing_invoice') ? (($invoiced_filter != 'true') ? 2 : 1) : 0);
		echo "<tr>\n";
		echo "	<td colspan='".$colspan."' style='text-align: center; padding: 30px; color: #999;'>".$text['label-no_cdrs']."</td>\n";
		echo "</tr>\n";
	}

	$total_colspan = permission_exists('billing_invoice') && $invoiced_filter != 'true' ? 8 : 7;
	echo "<tr class='list-row' style='font-weight: bold; background-color: #e8edf5; border-top: 2px solid #19376D;'>\n";
	echo "	<td colspan='".$total_colspan."' style='text-align: right; padding-right: 10px;'>".$text['label-total']." (".$i." calls):</td>\n";
	echo "	<td class='center' style='color: #19376D;'>Rp ".number_format($grand_total, 0, ',', '.')."</td>\n";
	if (permission_exists('billing_invoice')) {
		echo "	<td></td>\n";
	}
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	echo "<script>
function toggleAllCheckboxes(source) {
	var checkboxes = document.querySelectorAll('.row_checkbox');
	checkboxes.forEach(function(cb) { cb.checked = source.checked; });
	updateMarkButton();
}
function updateMarkButton() {
	var btn = document.getElementById('btn_mark_invoiced');
	if (!btn) return;
	var checked = document.querySelectorAll('.row_checkbox:checked').length;
	btn.style.display = checked > 0 ? 'inline-block' : 'none';
}
function confirmMarkInvoiced() {
	var checked = document.querySelectorAll('.row_checkbox:checked').length;
	if (checked === 0) { alert('".$text['label-select_records']."'); return; }
	if (confirm('".$text['confirm-mark_invoiced']."')) {
		document.getElementById('form_invoice').submit();
	}
}
document.addEventListener('DOMContentLoaded', updateMarkButton);
</script>";

	if (!empty($rows) && $i > 0) {
		echo "<div class='card' style='margin-top: 16px; padding: 16px; text-align: right;'>\n";
		echo "<div style='font-size: 24px; font-weight: bold; color: #19376D;'>Rp ".number_format($grand_total, 0, ',', '.')."</div>\n";
		echo "<div style='font-size: 11px; color: #888; margin-top: 4px;'>Total Tagihan</div>\n";
		echo "</div>\n";
	}

	require_once "resources/footer.php";

?>
