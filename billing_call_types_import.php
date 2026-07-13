<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('call_type_add')) {
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
			header('Location: billing_call_types.php');
			exit;
		}

		$field_map = $_POST['field_map'];
		$lines = explode("\n", $_POST['csv_import']);
		$delimiter = $_POST['data_delimiter'] ?? ',';
		$from_row = max(0, (int)($_POST['from_row'] ?? 1) - 1);

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

			if (empty($data['call_type_name'])) {
				$errors[] = "Row ".($i + 1).": call_type_name is required";
				continue;
			}

			$x = 0;
			$array['call_types'][$x]['call_type_uuid'] = uuid();
			$array['call_types'][$x]['domain_uuid'] = $_SESSION['domain_uuid'];
			$array['call_types'][$x]['call_type_name'] = $data['call_type_name'];
			$array['call_types'][$x]['call_type_description'] = $data['call_type_description'] ?? '';
			$array['call_types'][$x]['call_type_enabled'] = $data['call_type_enabled'] ?? 'true';

			$database->uuid($array['call_types'][$x]['call_type_uuid']);
			$database->save($array);
			$imported++;
		}

		$msg = sprintf($text['message-import_success'], $imported);
		message::add($msg, 'positive');
		if (!empty($errors)) {
			message::add(implode("<br>", array_slice($errors, 0, 10)), 'negative');
		}
		header('Location: billing_call_types.php');
		exit;
	}

	$document['title'] = $text['title-call_type_import'];
	require_once "resources/header.php";

	if ($action == 'preview' && !empty($_FILES['ulfile']['tmp_name']) && is_uploaded_file($_FILES['ulfile']['tmp_name'])) {
		$delimiter = $_POST['data_delimiter'] ?? ',';
		$csv_content = file_get_contents($_FILES['ulfile']['tmp_name']);
		$csv_content = mb_convert_encoding($csv_content, 'UTF-8');
		$lines = explode("\n", $csv_content);
		$headers = str_getcsv($lines[0] ?? '', $delimiter);

		$field_options = ['', 'call_type_name', 'call_type_description', 'call_type_enabled'];

		$object = new token;
		$token = $object->create($_SERVER['PHP_SELF']);

		echo "<form name='import_form' id='import_form' method='post'>\n";
		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".$text['title-call_type_import']."</b></div>\n";
		echo "	<div class='actions'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_call_types.php']);
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
	echo "	<div class='heading'><b>".$text['title-call_type_import']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_call_types.php']);
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
	echo "call_type_name,call_type_description,call_type_enabled<br>\n";
	echo "Nasional,Panggilan Nasional,true<br>\n";
	echo "Internasional,Panggilan Internasional,true\n";
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
