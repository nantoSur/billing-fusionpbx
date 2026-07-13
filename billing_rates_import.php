<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('provider_rate_add')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$action = $_POST['action'] ?? '';

	if ($action == 'import' && !empty($_POST['csv_import']) && !empty($_POST['field_map'])) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: billing_rates.php');
			exit;
		}

		$field_map = $_POST['field_map'];
		$lines = explode("\n", $_POST['csv_import']);
		$delimiter = $_POST['data_delimiter'] ?? ',';
		$from_row = max(0, (int)($_POST['from_row'] ?? 1) - 1);

		$providers_cache = [];
		$call_types_cache = [];

		$imported = 0;
		$errors = [];

		foreach ($lines as $i => $line) {
			if ($i < $from_row) continue;
			$line = trim($line);
			if (empty($line)) continue;

			$values = str_getcsv($line, $delimiter);
			$data = [];
			foreach ($field_map as $col_idx => $field_name) {
				if (!empty($field_name) && isset($values[$col_idx])) {
					$data[$field_name] = trim($values[$col_idx]);
				}
			}

			if (empty($data['rate_prefix'])) {
				$errors[] = "Row ".($i + 1).": rate_prefix is required";
				continue;
			}
			if (empty($data['rate_cost']) || !is_numeric($data['rate_cost'])) {
				$errors[] = "Row ".($i + 1).": rate_cost is required";
				continue;
			}

			$provider_name = $data['provider_name'] ?? '';
			if (!isset($providers_cache[$provider_name])) {
				if (!empty($provider_name)) {
					$sql = "select provider_uuid from v_providers where provider_name = :name and domain_uuid = :domain_uuid limit 1";
					$providers_cache[$provider_name] = $database->select($sql, ['name' => $provider_name, 'domain_uuid' => $_SESSION['domain_uuid']], 'column');
				} else {
					$providers_cache[$provider_name] = null;
				}
			}
			$provider_uuid = $providers_cache[$provider_name];

			$call_type_name = $data['call_type_name'] ?? '';
			if (!empty($call_type_name) && !isset($call_types_cache[$call_type_name])) {
				$sql = "select call_type_uuid from v_call_types where call_type_name = :name and domain_uuid = :domain_uuid limit 1";
				$call_types_cache[$call_type_name] = $database->select($sql, ['name' => $call_type_name, 'domain_uuid' => $_SESSION['domain_uuid']], 'column');
			} elseif (empty($call_type_name)) {
				$call_types_cache[$call_type_name] = null;
			}

			$x = 0;
			$array['provider_rates'][$x]['provider_rate_uuid'] = uuid();
			$array['provider_rates'][$x]['domain_uuid'] = $_SESSION['domain_uuid'];
			$array['provider_rates'][$x]['provider_uuid'] = $provider_uuid;
			$array['provider_rates'][$x]['call_type_uuid'] = $call_types_cache[$call_type_name] ?? null;
			$array['provider_rates'][$x]['rate_prefix'] = $data['rate_prefix'];
			$array['provider_rates'][$x]['rate_name'] = $data['rate_name'] ?? '';
			$array['provider_rates'][$x]['rate_cost'] = $data['rate_cost'];
			$array['provider_rates'][$x]['rate_setup_fee'] = $data['rate_setup_fee'] ?? 0;
			$array['provider_rates'][$x]['rate_increment'] = $data['rate_increment'] ?? 60;
			$array['provider_rates'][$x]['rate_currency'] = $data['rate_currency'] ?? 'IDR';
			$array['provider_rates'][$x]['rate_enabled'] = $data['rate_enabled'] ?? 'true';

			$database->uuid($array['provider_rates'][$x]['provider_rate_uuid']);
			$database->save($array);
			$imported++;
		}

		$msg = sprintf($text['message-import_success'], $imported);
		message::add($msg, 'positive');
		if (!empty($errors)) {
			message::add(implode("<br>", array_slice($errors, 0, 10)), 'negative');
		}
		header('Location: billing_rates.php');
		exit;
	}

	$document['title'] = $text['title-rate_import'];
	require_once "resources/header.php";

	if ($action == 'preview' && !empty($_FILES['ulfile']['tmp_name']) && is_uploaded_file($_FILES['ulfile']['tmp_name'])) {
		$delimiter = $_POST['data_delimiter'] ?? ',';
		$csv_content = file_get_contents($_FILES['ulfile']['tmp_name']);
		$csv_content = mb_convert_encoding($csv_content, 'UTF-8');
		$lines = explode("\n", $csv_content);
		$headers = str_getcsv($lines[0] ?? '', $delimiter);

		$field_options = ['', 'provider_name', 'call_type_name', 'rate_prefix', 'rate_name', 'rate_cost', 'rate_setup_fee', 'rate_increment', 'rate_currency', 'rate_enabled'];

		$object = new token;
		$token = $object->create($_SERVER['PHP_SELF']);

		echo "<form name='import_form' id='import_form' method='post'>\n";
		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".$text['title-rate_import']."</b></div>\n";
		echo "	<div class='actions'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_rates.php']);
		echo "		<button type='submit' class='btn btn-default' id='btn_import'>\n";
		echo "			<i class='fas fa-check fa-fw'></i><span class='button-label'>".$text['button-import_confirm']."</span>\n";
		echo "		</button>\n";
		echo "	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-import_csv_column']."</th>\n";
		echo "	<th>".$text['label-import_field']."</th>\n";
		echo "	<th>".$text['label-import_preview']."</th>\n";
		echo "</tr>\n";

		$preview_rows = array_slice($lines, 1, 5);
		foreach ($headers as $col_idx => $header) {
			$header = trim($header);
			$auto = in_array($header, $field_options) ? $header : '';
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($header)."</td>\n";
			echo "	<td>\n";
			echo "		<select class='formfld' name='field_map[$col_idx]'>\n";
			foreach ($field_options as $opt) {
				$sel = ($opt === $auto || $opt === $header) ? "selected='selected'" : '';
				echo "			<option value='$opt' $sel>".($opt ? escape($opt) : '-')."</option>\n";
			}
			echo "		</select>\n";
			echo "	</td>\n";
			echo "	<td style='font-size: 11px; color: #666;'>\n";
			$preview_vals = [];
			foreach ($preview_rows as $pr) {
				$vals = str_getcsv($pr, $delimiter);
				if (isset($vals[$col_idx])) {
					$preview_vals[] = escape(trim($vals[$col_idx]));
				}
			}
			echo implode(', ', $preview_vals);
			echo "	</td>\n";
			echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";

		echo "<input type='hidden' name='action' value='import'>\n";
		echo "<input type='hidden' name='csv_import' value='".escape($csv_content)."'>\n";
		echo "<input type='hidden' name='data_delimiter' value='".escape($delimiter)."'>\n";
		echo "<input type='hidden' name='from_row' value='2'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";

		require_once "resources/footer.php";
		exit;
	}

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	echo "<form name='upload_form' id='upload_form' method='post' enctype='multipart/form-data'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-rate_import']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_rates.php']);
	echo "		<button type='submit' class='btn btn-default' id='btn_upload'>\n";
	echo "			<i class='fas fa-upload fa-fw'></i><span class='button-label'>".$text['button-continue']."</span>\n";
	echo "		</button>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-import']."\n";
	echo "<br /><br />\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-import_file']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "    <input name='ulfile' type='file' class='formfld fileinput' id='ulfile' required='required'>\n";
	echo "<br />\n";
	echo $text['description-import_file']."\n";
	echo "<br /><br />\n";
	echo "<span style='font-size: 11px; color: #666; font-family: monospace;'>\n";
	echo "Contoh:<br>\n";
	echo "provider_name,rate_prefix,rate_name,rate_cost,rate_setup_fee,rate_increment,rate_enabled,call_type_name<br>\n";
	echo "TELKOM,0813,Indonesia Mobile,900,0,6,true,Nasional<br>\n";
	echo "XL AXIATA,0817,XL Mobile,850,0,6,true,Nasional\n";
	echo "</span>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-import_delimiter']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <select class='formfld' name='data_delimiter' style='width:60px;'>\n";
	echo "    <option value=','>,</option>\n";
	echo "    <option value='|'>|</option>\n";
	echo "    <option value=';'>;</option>\n";
	echo "    <option value='	'>tab</option>\n";
	echo "    </select>\n";
	echo "<br />\n";
	echo $text['description-import_delimiter']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	echo "<input type='hidden' name='action' value='preview'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";

?>
