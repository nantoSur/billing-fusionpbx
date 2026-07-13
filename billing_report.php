<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

	if (!permission_exists('billing_report') && !permission_exists('billing_view')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$start_date = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-01');
	$end_date = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');
	$end_date_plus_1 = date('Y-m-d', strtotime($end_date . ' +1 day'));
	$domain_uuid_selected = $_POST['domain_uuid'] ?? $_GET['domain_uuid'] ?? $_SESSION['domain_uuid'];
	$caller_id_filter = $_POST['caller_id'] ?? $_GET['caller_id'] ?? [];
	if (!is_array($caller_id_filter)) $caller_id_filter = [];
	$caller_id_filter = array_values(array_filter($caller_id_filter, function($v) { return $v !== ''; }));

	$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 0;

	$rows_per_page_options = [25, 50, 100];
	$rows_per_page = isset($_GET['rows_per_page']) && in_array($_GET['rows_per_page'], $rows_per_page_options)
		? (int)$_GET['rows_per_page']
		: 25;

	$param = "";
	$param .= "&start_date=".urlencode($start_date);
	$param .= "&end_date=".urlencode($end_date);
	if (!empty($domain_uuid_selected)) $param .= "&domain_uuid=".urlencode($domain_uuid_selected);
	if (!empty($rows_per_page)) $param .= "&rows_per_page=$rows_per_page";
	if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
		foreach ($caller_id_filter as $cid) {
			$param .= "&caller_id[]=".urlencode($cid);
		}
	}

	$self_url = 'billing_report.php';
	$param_prefix = !empty($param) ? '?'.ltrim($param, '&') : '';

	if (!empty($_POST['action']) && $_POST['action'] == 'delete_selected' && is_array($_POST['selected_ids'])) {
		if (permission_exists('billing_rates_delete')) {
			$selected_ids = $_POST['selected_ids'];
			$deleted_count = 0;

			foreach ($selected_ids as $id) {
				if (is_uuid($id)) {
					$array['cdr_rated'][0]['cdr_rated_uuid'] = $id;
					$database->delete($array);
					$deleted_count++;
				}
			}

			if ($deleted_count > 0) {
				message::add(sprintf($text['message-deleted_selected'], $deleted_count), 'positive');
			}

			header("Location: ".$self_url.$param_prefix);
			exit;
		}
	}

	$document['title'] = $text['title-billing_report'];
	require_once "resources/header.php";

	$rated_count = 0;

	if (!empty($_POST['action']) && $_POST['action'] == 'rate') {
		$start_date_p = $_POST['start_date'] ?? date('Y-m-01');
		$end_date_p = $_POST['end_date'] ?? date('Y-m-d');

		$sql = "select c.xml_cdr_uuid, c.domain_uuid, c.destination_number, c.billsec, c.last_arg, c.domain_name
			from v_xml_cdr c
			where c.direction = 'outbound'
			and c.billsec > 0
			and c.hangup_cause not in ('ORIGINATOR_CANCEL', 'CALL_REJECTED', 'NO_ANSWER', 'UNALLOCATED_NUMBER')
			and c.start_stamp >= :start_date
			and c.start_stamp < :end_date
			and not exists (select 1 from v_cdr_rated r where r.xml_cdr_uuid = c.xml_cdr_uuid)";

		$end_date_p_plus_1 = date('Y-m-d', strtotime($end_date_p . ' +1 day'));
		$parameters = [
			'start_date' => $start_date_p,
			'end_date' => $end_date_p_plus_1,
		];

		if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
			$sql .= " and c.domain_uuid = :domain_uuid";
			$parameters['domain_uuid'] = $domain_uuid_selected;
		} elseif (empty($_POST['domain_uuid']) || !permission_exists('domain_select')) {
			$sql .= " and c.domain_uuid = :domain_uuid";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		}

		if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
			$placeholders = [];
			foreach ($caller_id_filter as $i => $cid) {
				$p = "caller_id_$i";
				$placeholders[] = ":$p";
				$parameters[$p] = $cid;
			}
			$sql .= " and c.caller_id_number in (" . implode(',', $placeholders) . ")";
		}

		$sql .= " order by c.start_epoch asc";

		$cdrs = $database->select($sql, $parameters, 'all');

		if (!empty($cdrs)) {
			foreach ($cdrs as $cdr) {
				$destination = $cdr['destination_number'] ?? '';
				$last_arg = $cdr['last_arg'] ?? '';
				$domain_uuid = $cdr['domain_uuid'] ?? '';

				if (empty($destination)) {
					continue;
				}

				$gateway_name = '';
				if (preg_match('/sofia\/gateway\/([^\/]+)/i', $last_arg, $m)) {
					$gateway_name = $m[1];
				}

				$provider_uuid = null;
				if (!empty($gateway_name)) {
					$sql_gw = "select provider_uuid from v_gateways where (gateway = :gateway_name or gateway_uuid = :gateway_name2) and provider_uuid is not null limit 1";
					$provider_uuid = $database->select($sql_gw, ['gateway_name' => $gateway_name, 'gateway_name2' => $gateway_name], 'column');
				}

			$rate_sql = "
				select r.provider_rate_uuid, r.rate_prefix, r.rate_cost, r.rate_sale_cost, r.rate_setup_fee, r.rate_increment, r.rate_currency, r.provider_uuid
				from v_provider_rates r
				where r.rate_enabled = true
			";
				$rate_params = [];

				if ($provider_uuid) {
					$rate_sql .= " and r.provider_uuid = :provider_uuid";
					$rate_params['provider_uuid'] = $provider_uuid;
				}

				$rate_sql .= " and :destination like r.rate_prefix || '%'";
				$rate_params['destination'] = $destination;
				$rate_sql .= " order by length(r.rate_prefix) desc limit 1";

				$rate = $database->select($rate_sql, $rate_params, 'row');

				if (empty($rate)) {
					$sql_ins = "insert into v_cdr_rated (cdr_rated_uuid, domain_uuid, xml_cdr_uuid, total_cost, sale_cost, profit, invoiced, insert_date) ";
					$sql_ins .= "values (:cdr_rated_uuid, :domain_uuid, :xml_cdr_uuid, :total_cost, :sale_cost, :profit, :invoiced, now())";
					$database->execute($sql_ins, [
						'cdr_rated_uuid' => uuid(),
						'domain_uuid' => $domain_uuid,
						'xml_cdr_uuid' => $cdr['xml_cdr_uuid'],
						'total_cost' => 0,
						'sale_cost' => 0,
						'profit' => 0,
						'invoiced' => 'false',
					]);
					continue;
				}

				$increment = (int)($rate['rate_increment'] ?? 60);
				if ($increment < 1) $increment = 60;

				$billsec = (int)$cdr['billsec'];
				$billable_seconds = ceil($billsec / $increment) * $increment;

				$rate_cost_per_min = (float)($rate['rate_cost'] ?? 0);
				$setup_fee = (float)($rate['rate_setup_fee'] ?? 0);

				$rate_sale_cost_per_min = (float)($rate['rate_sale_cost'] ?? 0);
				$total_cost = ($rate_cost_per_min / 60) * $billable_seconds + $setup_fee;
				$total_cost = round($total_cost, 2);

				$sale_cost = $rate_sale_cost_per_min > 0 ? ($rate_sale_cost_per_min / 60) * $billable_seconds + $setup_fee : 0;
				$sale_cost = round($sale_cost, 2);
				$profit = $sale_cost > 0 ? round($sale_cost - $total_cost, 2) : 0;

				$sql_ins = "insert into v_cdr_rated (cdr_rated_uuid, domain_uuid, xml_cdr_uuid, provider_uuid, provider_rate_uuid, rate_prefix, rate_cost, rate_increment, billable_seconds, setup_fee, total_cost, sale_cost, profit, currency, invoiced, insert_date) ";
				$sql_ins .= "values (:cdr_rated_uuid, :domain_uuid, :xml_cdr_uuid, :provider_uuid, :provider_rate_uuid, :rate_prefix, :rate_cost, :rate_increment, :billable_seconds, :setup_fee, :total_cost, :sale_cost, :profit, :currency, :invoiced, now())";
				$database->execute($sql_ins, [
					'cdr_rated_uuid' => uuid(),
					'domain_uuid' => $domain_uuid,
					'xml_cdr_uuid' => $cdr['xml_cdr_uuid'],
					'provider_uuid' => $rate['provider_uuid'],
					'provider_rate_uuid' => $rate['provider_rate_uuid'],
					'rate_prefix' => $rate['rate_prefix'],
					'rate_cost' => $rate_cost_per_min,
					'rate_increment' => $increment,
					'billable_seconds' => $billable_seconds,
					'setup_fee' => $setup_fee,
					'total_cost' => $total_cost,
					'sale_cost' => $sale_cost,
					'profit' => $profit,
					'currency' => $rate['rate_currency'] ?? 'IDR',
					'invoiced' => 'false',
				]);

				$rated_count++;
			}
		}

		if ($rated_count > 0) {
			message::add(sprintf($text['label-rating_success'], $rated_count), 'positive');
		} else {
			message::add($text['label-no_unrated'], 'warning');
		}

		$redirect_params = "start_date=".urlencode($start_date)."&end_date=".urlencode($end_date);
		if (!empty($domain_uuid_selected)) $redirect_params .= "&domain_uuid=".urlencode($domain_uuid_selected);
		if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
			foreach ($caller_id_filter as $cid) {
				$redirect_params .= "&caller_id[]=".urlencode($cid);
			}
		}
		header("Location: ".$self_url."?".$redirect_params);
		exit;
	}

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-billing_report']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing.php']);
	$invoice_link = 'billing_invoice.php?start_date='.urlencode($start_date).'&end_date='.urlencode($end_date);
	if (!empty($domain_uuid_selected)) $invoice_link .= '&domain_uuid='.urlencode($domain_uuid_selected);
	if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
		foreach ($caller_id_filter as $cid) {
			$invoice_link .= '&caller_id[]='.urlencode($cid);
		}
	}
	echo button::create(['type'=>'button','label'=>$text['title-billing_invoice'],'icon'=>'file-invoice','link'=>$invoice_link]);
	if (permission_exists('billing_rates_delete')) {
		echo "		<button type='button' id='btn_delete_selected' class='btn btn-danger' style='display: none;' onclick='confirmDeleteSelected()'>\n";
		echo "			<i class='fas fa-trash'></i> ".$text['button-delete_selected']."\n";
		echo "		</button>\n";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form method='get' style='margin-bottom: 10px;'>\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='15%' class='vncell' valign='middle' align='left' nowrap>".$text['label-start_date']."</td>\n";
	echo "<td width='35%' class='vtable' align='left'><input type='date' class='formfld' name='start_date' value='".escape($start_date)."' style='max-width: 220px;'></td>\n";
	echo "<td width='15%' class='vncell' valign='middle' align='left' nowrap>".$text['label-end_date']."</td>\n";
	echo "<td class='vtable' align='left'><input type='date' class='formfld' name='end_date' value='".escape($end_date)."' style='max-width: 220px;'></td>\n";
	echo "</tr>\n";

	if (permission_exists('domain_select')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='middle' align='left' nowrap>".$text['label-select_domain']."</td>\n";
		echo "<td class='vtable' align='left' colspan='3'>\n";
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
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-caller']."</td>\n";
	echo "<td class='vtable' align='left' colspan='3'>\n";

	$sql_callers = "select distinct c.caller_id_number from v_cdr_rated r inner join v_xml_cdr c on r.xml_cdr_uuid = c.xml_cdr_uuid where c.direction = 'outbound' and c.billsec > 0 ";
	$caller_params = [];
	if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$sql_callers .= "and c.domain_uuid = :domain_uuid ";
		$caller_params['domain_uuid'] = $domain_uuid_selected;
	} elseif (empty($_GET['domain_uuid']) || !permission_exists('domain_select')) {
		$sql_callers .= "and c.domain_uuid = :domain_uuid ";
		$caller_params['domain_uuid'] = $_SESSION['domain_uuid'];
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

	echo "<tr>\n";
	echo "<td class='vncell'>&nbsp;</td>\n";
	echo "<td class='vtable' align='left' colspan='3'>\n";
	echo "	<button type='submit' class='btn' name='submit' style='background: #1a73e8; color: #fff; border: none; padding: 6px 16px; border-radius: 3px; cursor: pointer; font-size: 13px;'><i class='fas fa-search'></i> ".$text['label-search']."</button>\n";
	echo "	<select name='rows_per_page' class='formfld' style='width: auto; margin-left: 10px;' onchange='this.form.submit()'>\n";
	foreach ($rows_per_page_options as $option) {
		$sel = ($rows_per_page == $option) ? "selected='selected'" : "";
		echo "		<option value='$option' $sel>$option</option>\n";
	}
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	echo "<form method='post' id='form_list'>\n";
	echo "<input type='hidden' name='action' value='rate'>\n";
	echo "<input type='hidden' name='start_date' value='".escape($start_date)."'>\n";
	echo "<input type='hidden' name='end_date' value='".escape($end_date)."'>\n";
	echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid_selected)."'>\n";
	if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
		foreach ($caller_id_filter as $cid) {
			echo "<input type='hidden' name='caller_id[]' value='".escape($cid)."'>\n";
		}
	}
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "<tr>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='submit' class='btn' name='rate' value='".$text['button-rate_cdrs']."'>\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	$count_sql = "select count(r.cdr_rated_uuid) as num_rows
		from v_cdr_rated r
		inner join v_xml_cdr c on r.xml_cdr_uuid = c.xml_cdr_uuid
		where c.start_stamp >= :start_date
		and c.start_stamp < :end_date
		and c.direction = 'outbound' ";
	$count_params = [];
	$count_params['start_date'] = $start_date;
	$count_params['end_date'] = $end_date_plus_1;

	if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$count_sql .= "and c.domain_uuid = :domain_uuid ";
		$count_params['domain_uuid'] = $domain_uuid_selected;
	} elseif (empty($_GET['domain_uuid']) || !permission_exists('domain_select')) {
		$count_sql .= "and c.domain_uuid = :domain_uuid ";
		$count_params['domain_uuid'] = $_SESSION['domain_uuid'];
	}

	if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
		$count_placeholders = [];
		foreach ($caller_id_filter as $i => $cid) {
			$p = "caller_id_$i";
			$count_placeholders[] = ":$p";
			$count_params[$p] = $cid;
		}
		$count_sql .= "and c.caller_id_number in (" . implode(',', $count_placeholders) . ") ";
	}

	$num_rows = $database->select($count_sql, $count_params, 'column') ?? 0;

	list($paging_controls, $rows_per_page) = paging($num_rows, $param, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;

	$parameters = [];
	$sql = "select c.start_stamp, c.caller_id_number, c.destination_number, c.billsec, c.domain_name,
		r.cdr_rated_uuid, r.rate_prefix, r.rate_cost, r.rate_increment, r.billable_seconds, r.total_cost, r.sale_cost, r.profit, r.setup_fee, r.currency, r.provider_uuid,
		p.provider_name, pr.rate_name, ct.call_type_name
		from v_cdr_rated r
		inner join v_xml_cdr c on r.xml_cdr_uuid = c.xml_cdr_uuid
		left join v_providers p on r.provider_uuid = p.provider_uuid
		left join v_provider_rates pr on r.provider_rate_uuid = pr.provider_rate_uuid
		left join v_call_types ct on pr.call_type_uuid = ct.call_type_uuid
		where c.start_stamp >= :start_date
		and c.start_stamp < :end_date
		and c.direction = 'outbound' ";
	$parameters['start_date'] = $start_date;
	$parameters['end_date'] = $end_date_plus_1;

	if ($domain_uuid_selected != 'all' && is_uuid($domain_uuid_selected)) {
		$sql .= "and c.domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $domain_uuid_selected;
	} elseif (empty($_GET['domain_uuid']) || !permission_exists('domain_select')) {
		$sql .= "and c.domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}

	if (!empty($caller_id_filter) && is_array($caller_id_filter)) {
		$data_placeholders = [];
		foreach ($caller_id_filter as $i => $cid) {
			$p = "caller_id_$i";
			$data_placeholders[] = ":$p";
			$parameters[$p] = $cid;
		}
		$sql .= "and c.caller_id_number in (" . implode(',', $data_placeholders) . ") ";
	}

	$sql .= "order by c.start_stamp desc";
	$sql .= limit_offset($rows_per_page, $offset);

	$rated_rows = $database->select($sql, $parameters, 'all');

	echo "<form method='post' id='form_table'>\n";
	echo "<input type='hidden' name='action' value='delete_selected'>\n";
	echo "<input type='hidden' name='start_date' value='".escape($start_date)."'>\n";
	echo "<input type='hidden' name='end_date' value='".escape($end_date)."'>\n";
	echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid_selected)."'>\n";
	echo "<div class='card'>\n";

	if ($paging_controls_mini != '') {
		echo "<div style='padding: 8px;'>".$paging_controls_mini."</div>\n";
	}

	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('billing_rates_delete')) {
		echo "	<th class='checkbox' style='width: 30px;'><input type='checkbox' id='checkbox_all' onclick='toggleAllCheckboxes(this)'></th>\n";
	}
	echo "	<th>".$text['label-call_date']."</th>\n";
	echo "	<th>".$text['label-caller']."</th>\n";
	echo "	<th>".$text['label-destination_number']."</th>\n";
	echo "	<th>".$text['label-provider']."</th>\n";
	echo "	<th>".$text['label-call_type']."</th>\n";
	echo "	<th>".$text['label-destination']."</th>\n";
	echo "	<th class='center'>".$text['label-billsec']."</th>\n";
	echo "	<th class='center'>".$text['label-billable_seconds']."</th>\n";
	echo "  <th class='center'>".$text['label-rate_cost']."</th>\n";
	echo "  <th class='center'>".$text['label-cost']."</th>\n";
	echo "  <th class='center'>".$text['label-sale_price']."</th>\n";
	echo "  <th class='center'>".$text['label-profit']."</th>\n";
	echo "</tr>\n";

	$grand_total = 0;
	$total_calls = 0;

	if (!empty($rated_rows)) {
		foreach ($rated_rows as $row) {
			$cost = $row['total_cost'] ?? 0;
			$grand_total += $cost;
			$total_calls++;
			echo "<tr class='list-row'>\n";
			if (permission_exists('billing_rates_delete')) {
				echo "	<td class='checkbox'><input type='checkbox' name='selected_ids[]' value='".escape($row['cdr_rated_uuid'])."' class='row_checkbox' onclick='updateDeleteButton()'></td>\n";
			}
			echo "	<td>".escape(substr($row['start_stamp'], 0, 19))."</td>\n";
			echo "	<td>".escape($row['caller_id_number'] ?? '')."</td>\n";
			echo "	<td>".escape($row['destination_number'])."</td>\n";
			echo "	<td>".escape($row['provider_name'] ?? '-')."</td>\n";
			echo "	<td>".escape($row['call_type_name'] ?? '-')."</td>\n";
			echo "	<td>".escape($row['rate_name'] ?? '-')."</td>\n";
			echo "	<td class='center'>".escape($row['billsec'])."</td>\n";
			echo "	<td class='center'>".number_format($row['billable_seconds'] ?? 0, 0, ',', '.')."</td>\n";
			echo "  <td class='center'>Rp ".number_format($row['rate_cost'] ?? 0, 0, ',', '.')."/min</td>\n";
			echo "  <td class='center'>Rp ".number_format($cost, 0, ',', '.')."</td>\n";
			echo "  <td class='center'>Rp ".number_format($row['sale_cost'] ?? 0, 0, ',', '.')."</td>\n";
			echo "  <td class='center'>Rp ".number_format($row['profit'] ?? 0, 0, ',', '.')."</td>\n";
			echo "</tr>\n";
		}
	} else {
		echo "<tr>\n";
		$colspan = permission_exists('billing_rates_delete') ? 13 : 12;
		echo "	<td colspan='".$colspan."'>".$text['label-no_cdrs']."</td>\n";
		echo "</tr>\n";
	}

	echo "<tr class='list-row' style='font-weight: bold;'>\n";
	$colspan = permission_exists('billing_rates_delete') ? 10 : 9;
	echo "	<td colspan='".$colspan."' style='text-align: right;'>".$text['label-total']." ($total_calls calls):</td>\n";
	echo "	<td class='center'>Rp ".number_format($grand_total, 0, ',', '.')."</td>\n";
	echo "	<td></td>\n";
	echo "	<td></td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	if ($paging_controls != '') {
		echo "<div align='center'>".$paging_controls."</div>\n";
	}

	echo "<script>
function toggleAllCheckboxes(source) {
	var checkboxes = document.querySelectorAll('.row_checkbox');
	checkboxes.forEach(function(checkbox) {
		checkbox.checked = source.checked;
	});
	updateDeleteButton();
}

function updateDeleteButton() {
	var checkboxes = document.querySelectorAll('.row_checkbox');
	var btnDelete = document.getElementById('btn_delete_selected');
	var checkedCount = 0;
	checkboxes.forEach(function(checkbox) {
		if (checkbox.checked) checkedCount++;
	});
	if (btnDelete) {
		btnDelete.style.display = checkedCount > 0 ? 'inline-block' : 'none';
	}
}

function confirmDeleteSelected() {
	var checkboxes = document.querySelectorAll('.row_checkbox:checked');
	var count = checkboxes.length;
	if (count === 0) {
		alert('Please select at least one record.');
		return;
	}
	if (confirm('".$text['confirm-delete_selected']."')) {
		document.getElementById('form_table').submit();
	}
}

document.addEventListener('DOMContentLoaded', function() {
	updateDeleteButton();
});
</script>";

	require_once "resources/footer.php";

?>
