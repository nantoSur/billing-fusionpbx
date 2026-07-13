<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('billing_rates_delete')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	if (!empty($_GET["id"]) && is_uuid($_GET["id"])) {
		$array['provider_rates'][0]['provider_rate_uuid'] = $_GET["id"];
		$database->delete($array);
		message::add($text['message-rate_deleted']);
	}

	header('Location: billing_rates.php');
	exit;

?>
