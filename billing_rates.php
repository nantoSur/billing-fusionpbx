<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

	if (!permission_exists('billing_view')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$order_by = $_GET["order_by"] ?? '';
	$order = $_GET["order"] ?? '';
	$search = $_GET["search"] ?? '';
	$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 0;

	$rows_per_page_options = [25, 50, 100];
	$rows_per_page = isset($_GET['rows_per_page']) && in_array($_GET['rows_per_page'], $rows_per_page_options)
		? (int)$_GET['rows_per_page']
		: 25;

	$param = "";
	if (!empty($order_by)) $param .= "&order_by=$order_by";
	if (!empty($order)) $param .= "&order=$order";
	if (!empty($rows_per_page)) $param .= "&rows_per_page=$rows_per_page";
	if (!empty($search)) $param .= "&search=".urlencode($search);

	$self_url = 'billing_rates.php';
	$param_prefix = !empty($param) ? '?'.ltrim($param, '&') : '';

	if (!empty($_POST['delete_selected']) && is_array($_POST['selected_ids'])) {
		if (permission_exists('provider_rate_delete')) {
			$selected_ids = $_POST['selected_ids'];
			$deleted_count = 0;

			foreach ($selected_ids as $id) {
				if (is_uuid($id)) {
					$array['provider_rates'][0]['provider_rate_uuid'] = $id;
					$database->delete($array);
					$deleted_count++;
				}
			}

			if ($deleted_count > 0) {
				message::add(sprintf($text['message-deleted_selected'], $deleted_count), 'positive');
			}

			header('Location: '.$self_url.$param_prefix);
			exit;
		}
	}

	if (!empty($_GET['format']) && $_GET['format'] == 'csv') {
		$sql = "select p.provider_name, ct.call_type_name, r.rate_prefix, r.rate_name, r.rate_cost, r.rate_setup_fee, r.rate_increment, r.rate_currency, r.rate_enabled from v_provider_rates r ";
		$sql .= "left join v_providers p on r.provider_uuid = p.provider_uuid ";
		$sql .= "left join v_call_types ct on r.call_type_uuid = ct.call_type_uuid ";
		$sql .= "where r.domain_uuid = :domain_uuid order by r.rate_prefix asc";
		$rows = $database->select($sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'all');
		$filename = "rates_".date('Y-m-d').".csv";
		header("Content-Type: application/force-download");
		header("Content-Type: application/octet-stream");
		header("Content-Type: application/download");
		header("Content-Disposition: attachment;filename=".$filename);
		header("Content-Transfer-Encoding: binary");
		$out = fopen("php://output", 'w');
		fputcsv($out, ['provider_name', 'call_type_name', 'rate_prefix', 'rate_name', 'rate_cost', 'rate_setup_fee', 'rate_increment', 'rate_currency', 'rate_enabled']);
		if (!empty($rows)) {
			foreach ($rows as $row) {
				fputcsv($out, [
					$row['provider_name'] ?? '',
					$row['call_type_name'] ?? '',
					$row['rate_prefix'],
					$row['rate_name'] ?? '',
					$row['rate_cost'],
					$row['rate_setup_fee'] ?? 0,
					$row['rate_increment'] ?? 60,
					$row['rate_currency'] ?? 'IDR',
					$row['rate_enabled'] === true || $row['rate_enabled'] === 't' || $row['rate_enabled'] === 'true' ? 'true' : 'false',
				]);
			}
		}
		fclose($out);
		exit;
	}

	$document['title'] = $text['title-billing_rates'];
	require_once "resources/header.php";

	$sql = "select count(r.provider_rate_uuid) as num_rows from v_provider_rates r ";
	$sql .= "left join v_providers p on r.provider_uuid = p.provider_uuid ";
	$sql .= "left join v_call_types ct on r.call_type_uuid = ct.call_type_uuid ";
	$sql .= "where r.domain_uuid = :domain_uuid ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	if (!empty($search)) {
		$search_lower = strtolower($search);
		$sql .= "and (lower(r.rate_prefix) like :search or lower(r.rate_name) like :search or lower(p.provider_name) like :search or lower(ct.call_type_name) like :search) ";
		$parameters['search'] = '%'.$search_lower.'%';
	}
	$num_rows = $database->select($sql, $parameters, 'column') ?? 0;

	list($paging_controls, $rows_per_page) = paging($num_rows, $param, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;

	$sql = "select r.*, p.provider_name, ct.call_type_name from v_provider_rates r ";
	$sql .= "left join v_providers p on r.provider_uuid = p.provider_uuid ";
	$sql .= "left join v_call_types ct on r.call_type_uuid = ct.call_type_uuid ";
	$sql .= "where r.domain_uuid = :domain_uuid ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	if (!empty($search)) {
		$search_lower = strtolower($search);
		$sql .= "and (lower(r.rate_prefix) like :search or lower(r.rate_name) like :search or lower(p.provider_name) like :search or lower(ct.call_type_name) like :search) ";
		$parameters['search'] = '%'.$search_lower.'%';
	}
	$sql .= order_by($order_by, $order, 'r.rate_prefix', 'asc');
	$sql .= limit_offset($rows_per_page, $offset);
	$rates = $database->select($sql, $parameters, 'all');

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-billing_rates']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing.php']);
	if (permission_exists('billing_rates_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add_rate'],'icon'=>'plus','id'=>'btn_add','link'=>'billing_rate_edit.php']);
	}
	if (permission_exists('provider_rate_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-import_csv'],'icon'=>'upload','link'=>'billing_rates_import.php']);
	}
	echo button::create(['type'=>'button','label'=>'CSV','icon'=>'file-export','link'=>'billing_rates.php?format=csv']);
	if (permission_exists('provider_rate_delete')) {
		echo "		<button type='button' id='btn_delete_selected' class='btn btn-danger' style='display: none;' onclick='confirmDeleteSelected()'>\n";
		echo "			<i class='fas fa-trash'></i> ".$text['button-delete_selected']."\n";
		echo "		</button>\n";
	}
	echo "	</div>\n";
	echo "	<form method='get' style='display: inline-block; margin-left: 15px;'>\n";
	echo "		<select name='rows_per_page' class='formfld' style='width: auto;' onchange='this.form.submit()'>\n";
	foreach ($rows_per_page_options as $option) {
		$sel = ($rows_per_page == $option) ? "selected='selected'" : "";
		echo "			<option value='$option' $sel>$option</option>\n";
	}
	echo "		</select>\n";
	if (!empty($order_by)) {
		echo "		<input type='hidden' name='order_by' value='".escape($order_by)."'>\n";
	}
	if (!empty($order)) {
		echo "		<input type='hidden' name='order' value='".escape($order)."'>\n";
	}
	if (!empty($search)) {
		echo "		<input type='hidden' name='search' value='".escape($search)."'>\n";
	}
	echo "	</form>\n";
	if ($paging_controls_mini != '') {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>\n";
	}
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form method='post' id='form_list'>\n";
	echo "<input type='hidden' name='delete_selected' value='1'>\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('provider_rate_delete')) {
		echo "	<th class='checkbox' style='width: 30px;'><input type='checkbox' id='checkbox_all' onclick='toggleAllCheckboxes(this)'></th>\n";
	}
	echo th_order_by('p.provider_name', $text['label-provider'], $order_by, $order, null, null, '');
	echo th_order_by('ct.call_type_name', $text['label-call_type'], $order_by, $order, null, null, '');
	echo th_order_by('r.rate_prefix', $text['label-rate_prefix'], $order_by, $order, null, null, '');
	echo th_order_by('r.rate_name', $text['label-rate_name'], $order_by, $order, null, null, '');
	echo th_order_by('r.rate_cost', $text['label-rate_cost'], $order_by, $order, null, "class='center'", '');
	echo th_order_by('r.rate_setup_fee', $text['label-rate_setup_fee'], $order_by, $order, null, "class='center'", '');
	echo th_order_by('r.rate_increment', $text['label-rate_increment'], $order_by, $order, null, "class='center'", '');
	echo th_order_by('r.rate_enabled', $text['label-rate_enabled'], $order_by, $order, null, "class='center'", '');
	if (permission_exists('billing_rates_edit') || permission_exists('billing_rates_delete')) {
		echo "<th class='action-button'>&nbsp;</th>\n";
	}
	echo "</tr>\n";

	if (!empty($rates)) {
		foreach ($rates as $row) {
			$list_row_url = permission_exists('billing_rates_edit') ? "billing_rate_edit.php?id=".urlencode($row['provider_rate_uuid']) : '';
			$enabled_text = ($row['rate_enabled'] === true || $row['rate_enabled'] === 't' || $row['rate_enabled'] === 'true') ? 'true' : 'false';
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('provider_rate_delete')) {
				echo "	<td class='checkbox'><input type='checkbox' name='selected_ids[]' value='".escape($row['provider_rate_uuid'])."' class='row_checkbox' onclick='updateDeleteButton()'></td>\n";
			}
			echo "	<td>".escape($row['provider_name'] ?? '-')."</td>\n";
			echo "	<td>".escape($row['call_type_name'] ?? '')."</td>\n";
			echo "	<td>".escape($row['rate_prefix'])."</td>\n";
			echo "	<td>".escape($row['rate_name'] ?? '')."</td>\n";
			echo "	<td class='center'>Rp ".number_format($row['rate_cost'], 0, ',', '.')."</td>\n";
			echo "	<td class='center'>".number_format($row['rate_setup_fee'] ?? 0, 0, ',', '.')."</td>\n";
			echo "	<td class='center'>".escape($row['rate_increment'] ?? '60')."</td>\n";
			echo "	<td class='center'>".$text['label-'.$enabled_text]."</td>\n";
			if (permission_exists('billing_rates_edit') || permission_exists('billing_rates_delete')) {
				echo "	<td class='action-button'>\n";
				if (permission_exists('billing_rates_edit')) {
					echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>'edit','link'=>$list_row_url]);
				}
				if (permission_exists('billing_rates_delete')) {
					echo button::create(['type'=>'button','title'=>$text['button-delete'],'icon'=>'trash','link'=>'billing_rate_delete.php?id='.urlencode($row['provider_rate_uuid'])]);
				}
				echo "	</td>\n";
			}
			echo "</tr>\n";
		}
	} else {
		echo "<tr>\n";
		$colspan = permission_exists('provider_rate_delete') ? 11 : 10;
		echo "	<td colspan='".$colspan."'>".$text['label-no_rates']."</td>\n";
		echo "</tr>\n";
	}

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
		alert('Please select at least one rate.');
		return;
	}
	if (confirm('".$text['confirm-delete_selected']."')) {
		document.getElementById('form_list').submit();
	}
}

document.addEventListener('DOMContentLoaded', function() {
	updateDeleteButton();
});
</script>";

	require_once "resources/footer.php";

?>
