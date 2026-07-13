<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!(permission_exists('call_type_add') || permission_exists('call_type_edit'))) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_REQUEST["order_by"] ?? ''));
	$order = $_REQUEST["order"] ?? 'asc';

	if (!empty($_REQUEST["id"]) && empty($_REQUEST["a"])) {
		$action = "update";
		if (is_uuid($_REQUEST["id"])) {
			$call_type_uuid = $_REQUEST["id"];
		}
	} else {
		$action = "add";
		$call_type_uuid = uuid();
	}

	if (!empty($_REQUEST["a"]) && $_REQUEST["a"] == "delete" && !empty($_REQUEST["id"]) && is_uuid($_REQUEST["id"])) {
		if (permission_exists('call_type_delete')) {
			$array['call_types'][0]['call_type_uuid'] = $_REQUEST["id"];
			$database->delete($array);
			message::add($text['message-call_type_deleted'], 'positive');
		}
		header('Location: billing_call_types.php'.(!empty($order_by) ? '?order_by='.$order_by.'&order='.$order : ''));
		exit;
	}

	if (!empty($_POST)) {
		$call_type_name = $_POST["call_type_name"] ?? '';
		$call_type_description = $_POST["call_type_description"] ?? '';
		$call_type_enabled = $_POST["call_type_enabled"] ?? 'true';
	}

	if (!empty($_POST) && empty($_POST["persistformvar"])) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: billing_call_types.php');
			exit;
		}

		$msg = '';
		if (empty($call_type_name)) {
			$msg .= $text['message-required']." ".$text['label-call_type_name']."<br>\n";
		}

		if (!empty($msg) && empty($_POST["persistformvar"])) {
			require_once "resources/header.php";
			require_once "resources/persist_form_var.php";
			echo "<div align='center'>\n";
			echo "<table><tr><td>\n";
			echo $msg."<br />";
			echo "</td></tr></table>\n";
			persistformvar($_POST);
			echo "</div>\n";
			require_once "resources/footer.php";
			return;
		}

		if (empty($_POST["persistformvar"]) || $_POST["persistformvar"] != "true") {
			$x = 0;
			$array['call_types'][$x]["call_type_uuid"] = $call_type_uuid;
			$array['call_types'][$x]["domain_uuid"] = $_SESSION['domain_uuid'];
			$array['call_types'][$x]["call_type_name"] = $call_type_name;
			$array['call_types'][$x]["call_type_description"] = $call_type_description;
			$array['call_types'][$x]["call_type_enabled"] = $call_type_enabled;

			if (is_uuid($call_type_uuid)) {
				$database->uuid($call_type_uuid);
			}
			$database->save($array);

			if ($action == "add") {
				message::add($text['message-call_type_added']);
			} else {
				message::add($text['message-call_type_updated']);
			}
			header('Location: billing_call_types.php'.(!empty($order_by) ? '?order_by='.$order_by.'&order='.$order : ''));
			exit;
		}
	}

	if (!empty($_GET["id"]) && is_uuid($_GET["id"]) && empty($_POST["persistformvar"])) {
		$sql = "select * from v_call_types where call_type_uuid = :call_type_uuid and domain_uuid = :domain_uuid ";
		$parameters['call_type_uuid'] = $_GET["id"];
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row)) {
			$call_type_name = $row["call_type_name"];
			$call_type_description = $row["call_type_description"];
			$call_type_enabled = $row["call_type_enabled"];
		}
		unset($sql, $parameters, $row);
	}

	$call_type_name = $call_type_name ?? '';
	$call_type_description = $call_type_description ?? '';
	$call_type_enabled = $call_type_enabled ?? true;

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$document['title'] = $text['title-billing_call_type_edit'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-billing_call_type_edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_call_types.php'.(!empty($order_by) ? '?order_by='.$order_by.'&order='.$order : '')]);
	echo button::create(['type'=>'button','label'=>$text['button-save_call_type'],'icon'=>'check','id'=>'btn_save','style'=>'margin-left: 15px;','onclick'=>'document.frm.submit();']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form name='frm' method='post'>\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-call_type_name']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='call_type_name' maxlength='255' value=\"".escape($call_type_name)."\" required='required'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-call_type_description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='call_type_description' maxlength='255' value=\"".escape($call_type_description)."\">\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-call_type_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='call_type_enabled'>\n";
	$selected = ($call_type_enabled === true || $call_type_enabled === 't' || $call_type_enabled === 'true') ? 'true' : 'false';
	echo "		<option value='true' ".($selected == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($selected == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	if ($action == "update") {
		echo "<input type='hidden' name='id' value='".escape($call_type_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";

?>
