<?php

opcache_reset();

// ============================================================
// Section: Debugging
// ============================================================
register_shutdown_function(function() {
	$error = error_get_last();
	$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
	if ($error !== null && in_array($error['type'], $fatal_types)) {
		if (ob_get_level() > 0) ob_end_clean();
		echo "<div style='border: 3px solid red; padding: 20px; background: #fff0f0; color: #a00; font-family: sans-serif;'>";
		echo "<h2>⚠️ PHP ERROR </h2>";
		echo "<strong>Message:</strong> " 	. htmlspecialchars($error['message']) 	. "<br>";
		echo "<strong>File:</strong> " 		. htmlspecialchars($error['file']) 		. "<br>";
		echo "<strong>Line:</strong> " 		. $error['line'];
		echo "</div>";
	}
});
ob_start();
require 'scripts/Customer.php';
// ------------------------------------------------------------

function prettyPrint($name, $var) {
    echo "<hr>";
    echo "<strong>{$name}</strong><br>";
    echo "<pre style='background:#f6f8fa; padding:10px; border:1px solid #ddd; border-radius:5px; overflow:auto;'>";
    echo htmlspecialchars(print_r($var, true), ENT_QUOTES, 'UTF-8');
    echo "</pre>";
    echo "<hr>";
}

function fetch($request) {
	require 'scripts/Config.php';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $env_local_base . '/URLServerConnect',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($request),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

	$response_raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response_raw === false) {
        echo 'cURL error: ' . curl_error($ch);
    } elseif ($http_code >= 400) {
        echo "Server Error ($http_code): Check the backend file for syntax errors.";
        echo "<pre>" . htmlspecialchars($response_raw) . "</pre>";
        die(); // Stop the page so you can see the error
    }
    curl_close($ch);

    $response = json_decode($response_raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Decode Error: " . json_last_error_msg();
        echo "<pre>" . htmlspecialchars($response_raw) . "</pre>";
        die();
    }

    if (isset($response['session_var']) && count((array)$response['session_var']) > 0) {
        $_SESSION = (array)$response['session_var'];
    }

    if (isset($request['calls']) && is_array($request['calls'])) {
        foreach ($response as $key => $subResponse) {
            if ($key === 'session_var' || !is_array($subResponse)) continue;

            if (isset($subResponse['status']) && $subResponse['status'] === 'error') {
                echo "<div style='background:#f8d7da; color:#721c24; padding:10px; margin:5px; border:1px solid #f5c6cb;'>";
                echo "<strong>Error in Batch Call ID [$key]:</strong><br>";
                
                if (function_exists('prettyPrint')) {
                    prettyPrint('', $subResponse);
                } else {
                    echo "<pre>"; print_r($subResponse); echo "</pre>";
                }
                echo "</div>";
            }
        }
    } else {
        if (isset($response['status']) && $response['status'] === 'error') {
            if (function_exists('prettyPrint')) {
                prettyPrint('$response', $response);
            } else {
                echo "<div style='background:#f8d7da; color:#721c24; padding:10px; border:1px solid #f5c6cb;'>";
                echo "<pre>"; print_r($response); echo "</pre>";
                echo "</div>";
            }
        }
    }

    if (isset($response['status']) && $response['status'] === 'error') {
        if(function_exists('prettyPrint')) {
            prettyPrint('$response', $response);
        } else {
            echo "<pre>"; print_r($response); echo "</pre>";
        }
    }

	return $response;
}

$PN = "Cust";
$PN1 = "CRMSalesPortal";
$pgname = 'Marketing Portal';
include 'index.php';

function findNameById($array, $keyField, $valueField, $id) {
	foreach ($array as $row) {
		if ($row[$keyField] == $id) {
			return $row[$valueField];
		}
	}
	return '';
}

// --- API Logic ---
$init = fetch([
	'session_var' => $_SESSION,
	'targetpage'  => 'Customer',
	'funCall'     => 'getMarketingPortal'
]);
prettyPrint('$init', $init);
exit();

$customer_enquiry_status	= $init['customer_enquiry_status'];
$customer_company			= $init['customer_company'];
$customer_enquiry			= $init['customer_enquiry'];
$customer_FIBU_product_type	= $init['customer_FIBU_product_type'];
$customer_location			= $init['customer_location'];
$customer_product_category	= $init['customer_product_category'];
$customer_update_type		= $init['customer_update_type'];
$customer_market_segment	= $init['customer_market_segment'];
$customer_category			= $init['customer_category'];
$customer_enquiry_source	= $init['customer_enquiry_source'];
$employee_list				= $init['employee_list'];


?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<link href="css/select2.min.css" rel="stylesheet" />
	<script src="js/select2.min.js"></script>
	<script src="js/sweetalert2011.js"></script>
	<script src="js/emailpreviewer.js"></script>
    <title>Marketing Portal</title>
	<style>
    table {
        border-collapse: collapse;
        width: 100%;
        /*min-width: max-content;*/ /* enables horizontal scroll */
    }
    th {
        border: 1px solid #dee2e6;
        font-size: 1.7rem;
        white-space: nowrap;
    }
    td {
        border: 1px solid #dee2e6;
        padding: 0 !important;
        margin: 0 !important;
        white-space: nowrap;
    }
    .excel-input {
        width: 100%;
        height: 100%;
        border: none;
        outline: none;
        padding: 4px;
        margin: 0;
        font-size: 1.7rem;
    }
    .excel-input:focus {
        box-shadow: none;
    }
    .add-col-btn {
        width: 100%;
        border-radius: 0;
    }
    /* Scroll container */
    .table-scroll {
        width: 100%;
        height: 80vh;
        overflow: auto; /* both vertical + horizontal */
        border: 1px solid #dee2e6;
    }
	.tabs {
        display: flex;
        list-style-type: none;
        padding: 0;
        margin: 0 0 10px 0;
        border-bottom: 2px solid #ccc;
    }
    .tabs li {
        margin-right: 10px;
    }
    .tabs a {
        display: block;
        padding: 8px 16px;
        text-decoration: none;
        background: red; /* Default for inactive */
        color: white;
        border: 1px solid #ccc;
        border-bottom: none;
        border-radius: 5px 5px 0 0;
    }
    .tabs a.active {
        background: green; /* Active tab */
        font-weight: bold;
        color: white;
    }
    .tab-content {
        display: none;
        border: 1px solid #ccc;
        padding: 1px;
        background: #fff;
    }
    .tab-content.active {
        display: block;
    }
	.clickable-row:hover {
		cursor: pointer;
		background-color: #e9ecef; /* light grey */
		transition: background-color 0.2s ease;
	}
</style>
</head>
<body>
	<!-- /. NAV SIDE  -->
	<div id="page-wrapper">
		<!-- Tabs Navigation -->
		<ul class="tabs">
			<li><button class="btn btn-primary" style="font-size: 1.9rem;" onclick="addEnquiry()">Add Enquiry</button></li>
			<?php foreach ($customer_enquiry_status as $key => $value): ?>
				<?php $full_stage = $value["name"];
					$displayedstage = substr($full_stage, 0, 10);
				?>
				<li><a href="#tab<?= $value["customer_enquiry_status_id"] ?>" title="<?php echo $full_stage; ?>" onmouseover="this.innerHTML='<?php echo $full_stage; ?>'" onmouseout="this.innerHTML='<?php echo $displayedstage; ?>'"><?= htmlspecialchars($displayedstage) ?></a></li>
			<?php endforeach; ?>
		</ul>
		<!-- Tabs Content -->
		<?php foreach ($customer_enquiry_status as $key => $value): ?>
			<div id="tab<?= $value["customer_enquiry_status_id"] ?>" class="tab-content" style="display: none;">
				<div class="table-scroll mb-2">
					<table border="2" id="dataTable<?php echo $value["customer_enquiry_status_id"]; ?>" class="table table-bordered mb-0" style="width:100%; table-layout:fixed;">
						<thead><tr align='center'>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 8rem;" title="Enquiry no."><b>Enquiry no.</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 10rem;" title="Enquiry Date"><b>Enquiry Date</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 12rem;" title="Initiator"><b>Initiator</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 15rem;" title="Company Name"><b>Company Name</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 14rem;" title="Contact Person"><b>Contact Person</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 14rem;" title="Market Segment"><b>Market Segment</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 10rem;" title="Customer Category"><b>Customer Category</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 15rem;" title="Product Vertical"><b>Product Vertical</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 16rem;" title="Relevant FI BU Product Type"><b>Relevant FI BU Product Type</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 8rem;" title="Source"><b>Source</b></th>
							<th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;width: 8rem;" title="Estimated Cost"><b>Estimated Cost</b></th>
						</tr></thead>
						<tbody id="tableBody<?php echo$value["customer_enquiry_status_id"]; ?>">
				<?php if (!empty($customer_enquiry[$value["customer_enquiry_status_id"]])): ?>
					<?php foreach ($customer_enquiry[$value["customer_enquiry_status_id"]] as $enq): ?>
						<tr align="center" class="clickable-row" onclick="showDetails('details<?= $enq['customer_enquiry_id'] ?>',this)">
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['fi_customer_enquiry_id'] ?>"><?= $enq['fi_customer_enquiry_id'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= date('d M Y',strtotime($enq['enquiry_date'])) ?>"><?= date('d M Y',strtotime($enq['enquiry_date'])) ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['creator'] ?>"><?= $enq['creator'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_company']['name'] ?>"><?= $enq['customer_company']['name'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['contact_person']['contact_person'] ?>"><?= $enq['contact_person']['contact_person'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_market_segment'] ?>"><?= $enq['customer_market_segment'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_category'] ?>"><?= $enq['customer_category'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_product_category'] ?>"><?= $enq['customer_product_category'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_FIBU_product_type'] ?>"><?= $enq['customer_FIBU_product_type'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['customer_enquiry_source'] ?>"><?= $enq['customer_enquiry_source'] ?></td>
							<td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= $enq['estimated_cost'] ?>"><?= $enq['estimated_cost'] ?></td>
						</tr>
						<tr><td colspan="11">
						<?php 
							$enquiryId = $enq['customer_enquiry_id'];
							echo '<div style="display: none;text-align:left; font-size: 1.5rem;padding: 15px;background-color: #e9ecef;" class="details_section" id="details'. htmlspecialchars($enquiryId).'">
								<h3><b>Company Details:</b></h3><hr/>
								<div class="customer_company" id="customer_company'.$enquiryId.'" style="width: 100%;">';
							$isEditable = ($_SESSION['employees_id'] == $enq['customer_company']['created_by']);
							$readonly   = $isEditable ? '' : 'readonly';
							$disabled   = $isEditable ? '' : 'disabled';
							echo '<input type="hidden" id="customer_company_id" name="customer_company_id" value="'.htmlspecialchars($enq['customer_company']['customer_company_id']).'">';
							echo '<div class="container-fluid">';
							$colCount = 0;
							foreach ($enq['customer_company'] as $key => $value) {
								if (in_array($key, ['assigned_employees_id','qualified_marketing_lead','customer_company_id','contact_person','createdate','updatedate','customer_marketing_lead_status_id','created_by']))
									continue;
								if ($colCount % 4 == 0) echo '<div class="row">';
								echo '<div class="col-md-3 mb-3">';
								// ===== LOCATION DROPDOWN =====
								if ($key == "customer_location_id") {
									echo '<label class="form-label">State</label><br>';
									echo '<select class="form-control select2" id="'.$key.'" name="'.$key.'" '.$disabled.'>';
									echo '<option value="">Select</option>';
									foreach ($customer_location as $loc) {
										$selected = ($value == $loc['customer_location_id']) ? 'selected' : '';
										echo '<option value="'.$loc['customer_location_id'].'" '.$selected.'>
												'.$loc['country'].' : '.$loc['state'].'
											  </option>';
									}
									echo '</select>';
								}
								// ===== MARKET SEGMENT =====
								elseif ($key == "customer_market_segment_id") {
									echo '<label class="form-label">Market Segment</label><br>';
									echo '<select class="form-control select2" id="'.$key.'" name="'.$key.'" '.$disabled.'>';
									echo '<option value="">Select</option>';
									foreach ($customer_market_segment as $seg) {
										$selected = ($value == $seg['customer_market_segment_id']) ? 'selected' : '';
										echo '<option value="'.$seg['customer_market_segment_id'].'" '.$selected.'>
												'.$seg['name'].'
											  </option>';
									}
									echo '</select>';
								}
								// ===== CATEGORY =====
								elseif ($key == "customer_category_id") {
									echo '<label class="form-label">Customer Category</label><br>';
									echo '<select class="form-control select2" id="'.$key.'" name="'.$key.'" '.$disabled.'>';
									echo '<option value="">Select</option>';
									foreach ($customer_category as $cat) {
										$selected = ($value == $cat['customer_category_id']) ? 'selected' : '';
										echo '<option value="'.$cat['customer_category_id'].'" '.$selected.'>
												'.$cat['name'].'
											  </option>';
									}
									echo '</select>';
								}
								// ===== NOTE TEXTAREA =====
								elseif ($key == "note") {
									echo '<label class="form-label">'.ucwords(str_replace('_',' ',$key)).'</label>';
									echo '<textarea class="form-control" 
												id="'.$key.'" 
												name="'.$key.'" 
												'.$readonly.'>'
												.htmlspecialchars($value).
										  '</textarea>';
								}
								// ===== NOTE TEXTAREA =====
								elseif ($key == "contact") {
									echo '<label class="form-label">'.ucwords(str_replace('_',' ',$key)).'</label>';
									echo '<input type="text" 
												class="form-control" style="padding: .375rem .75rem;"
												id="'.$key.'" 
												name="'.$key.'" 
												value="'.htmlspecialchars($value).'" 
												'.$readonly.'>';
								}
								// ===== DEFAULT INPUT =====
								else {
									echo '<label class="form-label">'.ucwords(str_replace('_',' ',$key)).'</label>';
									echo '<input type="text" 
												class="form-control" 
												id="'.$key.'" 
												name="'.$key.'" 
												value="'.htmlspecialchars($value).'" 
												'.$readonly.'>';
								}
								echo '</div>';
								if ($colCount % 4 == 3) echo '</div>';
								$colCount++;
							}
							if ($colCount % 4 != 0) echo '</div>';
							echo '</div></div>';
							echo '<label><b>Contact Person Details:</b></label>
								<div class="contact_person" id="contact_person'.$enquiryId.'" style="width: 100%;">';
							$isEditable = ($_SESSION['employees_id'] == $enq['contact_person']['created_by']);
							$readonly   = $isEditable ? '' : 'readonly';
							$disabled   = $isEditable ? '' : 'disabled';
							echo '<input type="hidden" id="customer_company_department_id" name="customer_company_department_id" value="'.htmlspecialchars($enq['contact_person']['customer_company_department_id']).'">';
							echo '<div class="container-fluid">';
							$colCount = 0;
							foreach ($enq['contact_person'] as $key => $value) {
								if (in_array($key, [
									'customer_company_department_id',
									'createdate',
									'updatedate',
									'customer_company_id',
									'created_by'
								])) continue;
								if ($colCount % 4 == 0) echo '<div class="row">';
								echo '<div class="col-md-3 mb-3">';
								// ===== LABEL NAME FIX =====
								$label = ($key == 'name') 
									? 'Department Name' 
									: ucwords(str_replace('_',' ',$key));
								echo '<label class="form-label">'.$label.'</label>';
								// ===== MULTI SELECT : PRODUCT CATEGORY =====
								if ($key == "customer_product_category") {
									$selectedValues = explode(',', $value);
									echo '<br><select class="form-control select2" 
												id="'.$key.'" 
												name="'.$key.'[]" 
												multiple 
												'.$disabled.'>';
									foreach ($customer_product_category as $cat) {
										$selected = in_array($cat['customer_product_category_id'], $selectedValues) ? 'selected' : '';
										echo '<option value="'.$cat['customer_product_category_id'].'" '.$selected.'>
												'.$cat['name'].'
											  </option>';
									}
									echo '</select>';
								}
								// ===== MULTI SELECT : FIBU PRODUCT TYPE =====
								elseif ($key == "customer_FIBU_product_type") {
									$selectedValues = explode(',', $value);
									echo '<br><select class="form-control select2" 
												id="'.$key.'" 
												name="'.$key.'[]" 
												multiple 
												'.$disabled.'>';
									foreach ($customer_FIBU_product_type as $type) {
										$selected = in_array($type['customer_FIBU_product_type_id'], $selectedValues) ? 'selected' : '';
										echo '<option value="'.$type['customer_FIBU_product_type_id'].'" '.$selected.'>
												'.$type['name'].'
											  </option>';
									}
									echo '</select>';
								}
								// ===== DEFAULT INPUT =====
								else {
									echo '<input type="text" 
												class="form-control" 
												id="'.$key.'" 
												name="'.$key.'" 
												value="'.htmlspecialchars($value).'" 
												'.$readonly.'>';
								}
								echo '</div>';
								if ($colCount % 4 == 3) echo '</div>';
								$colCount++;
							}
							if ($colCount % 4 != 0) echo '</div>';
							echo '</div></div>
								<br><br>
								<h3><b>Enquiry Details</b></h3><hr/>
								<div class="customer_enquiry_stages" style="width: 100%;">';
									echo '<div class="table-responsive">';
										echo '<table class="table table-bordered text-center align-middle"  id="customer_enquiry_stages'.$enquiryId.'">';
											echo '<thead>';
												// HEADER ROW 1 (Main Stages)
												echo '<tr><th rowspan="2" style="width: 15rem;"></th>';
												foreach ($enq['enquiry_stages'] as $stage) {
													if (!empty($stage['sub_stages'])) {
														echo '<th colspan="'.count($stage['sub_stages']).'">';
														echo '<b>'.$stage['name'].'</b><br>';
														echo '<small>'.$stage['description'].'</small>';
														echo '</th>';
													} else {
														echo '<th rowspan="2" style="width: 10rem;">';
														echo '<b>'.$stage['name'].'</b><br>';
														echo '<small style="white-space: pre-wrap;">'.$stage['description'].'</small>';
														echo '</th>';
													}
												}
												echo '</tr>';
												// HEADER ROW 2 (Sub Stages)
												echo '<tr>';
												foreach ($enq['enquiry_stages'] as $stage) {
													if (!empty($stage['sub_stages'])) {
														foreach ($stage['sub_stages'] as $sub) {
															echo '<th style="white-space: pre-wrap;width: 10rem;">';
															echo '<b>'.$sub['name'].'</b><br>';
															echo '<small>'.$sub['description'].'</small>';
															echo '</th>';
														}
													}
												}
												echo '</tr>';
											echo '</thead>';
											echo '<tbody>';
												// ROW 1 : RATING
												echo '<tr><td>Rating</td>';
												foreach ($enq['enquiry_stages'] as $stage) {
													$editable = ($enq['customer_enquiry_status_id'] == $stage['customer_enquiry_stages_id']);
													$disabled = $editable ? '' : 'disabled';
													if (!empty($stage['sub_stages'])) {
														foreach ($stage['sub_stages'] as $sub) {
															$parentValueId = $stage['values']['customer_enquiry_stages_values_id'];
															$valueId = $sub['values']['customer_enquiry_stages_values_id'];
															$rating  = isset($sub['values']['rating']) ? $sub['values']['rating'] : 0;
															echo '<td>';
															echo '<select class="form-control rating_parent_'.$parentValueId.'" 
																	parentValueId="'.$parentValueId.'" customer_enquiry_stages_values_id="'.$valueId.'" '.$disabled.'>';
															echo '<option value="0">Select</option>';
															for ($i=1; $i<=5; $i++) {
																$selected = ($rating == $i) ? 'selected' : '';
																echo '<option value="'.$i.'" '.$selected.'>'.$i.'</option>';
															}
															echo '</select>';
															echo '</td>';
														}
													}
													else {
														$valueId = $stage['values']['customer_enquiry_stages_values_id'];
														$rating  = isset($stage['values']['rating']) ? $stage['values']['rating'] : 0;
														echo '<td>';
														echo '<select class="form-control" 
																customer_enquiry_stages_values_id="'.$valueId.'" '.$disabled.'>';
														echo '<option value="0">Select</option>';
														for ($i=1; $i<=5; $i++) {
															$selected = ($rating == $i) ? 'selected' : '';
															echo '<option value="'.$i.'" '.$selected.'>'.$i.'</option>';
														}
														echo '</select>';
														echo '</td>';
													}
												}
												echo '</tr>';
												// ROW 2 : STATUS
												echo '<tr><td>Status</td>';
												foreach ($enq['enquiry_stages'] as $stage) {
													$editable = ($enq['customer_enquiry_status_id'] == $stage['customer_enquiry_stages_id']);
													$disabled = $editable ? '' : 'disabled';
													// ===== WITH SUB STAGES =====
													if (!empty($stage['sub_stages'])) {
														foreach ($stage['sub_stages'] as $sub) {
															$parentValueId = $stage['values']['customer_enquiry_stages_values_id'];
															$valueId = $sub['values']['customer_enquiry_stages_values_id'];
															$status  = $sub['values']['status'];
															echo '<td>';
															echo '<select 
																	class="form-control status_parent_'.$parentValueId.'" 
																	parentValueId="'.$parentValueId.'" 
																	customer_enquiry_stages_values_id="'.$valueId.'"
																	'.$disabled.'>';
															echo '<option value="0" '.($status==0?'selected':'').'>Pending</option>';
															echo '<option value="1" '.($status==1?'selected':'').'>Completed</option>';
															echo '</select>';
															echo '</td>';
														}
													}
													// ===== WITHOUT SUB STAGES =====
													else {
														$valueId = $stage['values']['customer_enquiry_stages_values_id'];
														$status  = $stage['values']['status'];
														echo '<td>';
														echo '<select 
																class="form-control" 
																customer_enquiry_stages_values_id="'.$valueId.'"
																'.$disabled.'>';
														echo '<option value="0" '.($status==0?'selected':'').'>Pending</option>';
														echo '<option value="1" '.($status==1?'selected':'').'>Completed</option>';
														echo '</select>';
														echo '</td>';
													}
												}
												echo '</tr>';
												// ROW 3 : COMPLETION DATE
												echo '<tr><td>Completion Date</td>';
												foreach ($enq['enquiry_stages'] as $stage) {
													// ===== WITH SUB STAGES =====
													if (!empty($stage['sub_stages'])) {
														foreach ($stage['sub_stages'] as $sub) {
															$status  = $sub['values']['status'];
															$date    = $sub['values']['updatedate'];
															echo '<td>';
															if ($status == 1) {
																echo date("d M Y", strtotime($date));
															}
															echo '</td>';
														}
													}
													// ===== WITHOUT SUB STAGES =====
													else {
														$status  = $stage['values']['status'];
														$date    = $stage['values']['updatedate'];
														echo '<td>';
														if ($status == 1) {
															echo date("d-m-Y", strtotime($date));
														}
														echo '</td>';
													}
												}
												echo '</tr>';
											echo '</tbody>';
										echo '</table>';
									echo '</div>';
							echo '</div>
								<div class="other_details" id="other_details'.$enquiryId.'" style="width: 100%;">
								<div class="row" style="margin-top: 10px;">
									<div class="col-md-2">
										<label class="form-label">Source:</label>
										<select id="customer_enquiry_source_id" class="form-control" value="'.$enq['customer_enquiry_source_id'].'" '.(!empty($enq['customer_enquiry_source_id']) && $enq['customer_enquiry_source_id'] != 0 ? 'disabled' : '').'>
											<option value="">Select</option>';
            								foreach($customer_enquiry_source as $source){
												$selected = ($source['customer_enquiry_source_id'] == $enq['customer_enquiry_source_id']) ? 'selected' : '';
												echo '<option value="'.$source['customer_enquiry_source_id'].'" '.$selected.'>'.$source['name'].' </option>';
											}
										echo'</select>
									</div>
									<div class="col-md-6">
										<label class="form-label">Note Regarding Source:</label>
										<textarea id="source_note" class="form-control" style="margin: 0; height: 34px;">'.$enq['source_note'].'</textarea>
									</div>
									<div class="col-md-2">
										<label class="form-label">Estimated Cost:</label>
										<input type="number" id="estimated_cost" class="form-control" value="'.$enq['estimated_cost'].'" style="margin: 0;" />
									</div>
									<div class="col-md-2">
										<label class="form-label">Enquiry Status:</label>
										<select id="customer_enquiry_status_id" class="form-control" value="'.$enq['customer_enquiry_status_id'].'">';
            								$temp_status_id=[];
											foreach ($enq['enquiry_stages'] as $stage) {
												$temp_status_id[]=$stage['customer_enquiry_stages_id'];
											}
											foreach($customer_enquiry_status as $status){
												if($status['customer_enquiry_status_id'] == $enq['customer_enquiry_status_id']){
													echo '<option value="'.$status['customer_enquiry_status_id'].'" selected>'.$status['name'].' </option>';
												}
												if($status['customer_enquiry_status_id'] != $enq['customer_enquiry_status_id'] && !in_array($status['customer_enquiry_status_id'],$temp_status_id)){
													echo '<option value="'.$status['customer_enquiry_status_id'].'">'.$status['name'].' </option>';
												}
											}
										echo'</select>
									</div>
								</div>';
							echo '</div>
								<div class="enquiry_updates" style="width: 100%;">';
								// 1️. STATUS UPDATES (TYPE 2)
								echo '<h4 class="mt-4"><b>Status Updates</b></h4>';
								echo '<table class="table table-bordered table-sm">';
								echo '<thead><tr>
										<th style="width: 10%;">Date</th>
										<th style="width: 10%;">Status</th>
										<th style="width: 60%;">Comment</th>
										<th style="width: 20%;">Employee</th>
									  </tr></thead><tbody>';
								foreach ($enq['updates'] as $u) {
									if ($u['customer_update_type_id'] == 2) {
										$statusName = findNameById(
											$customer_enquiry_status,
											'customer_enquiry_status_id',
											'name',
											$u['selected_id']
										);
										echo '<tr>';
										echo '<td style="padding: 5px !important;">'.date("d M Y", strtotime($u['createdate'])).'</td>';
										echo '<td style="padding: 5px !important;">'.$statusName.'</td>';
										echo '<td style="padding: 5px !important;">'.$u['employee_update'].'</td>';
										echo '<td style="padding: 5px !important;">'.$u['name'].'</td>';
										echo '</tr>';
									}
								}
								echo '</tbody></table>';
								// 2️. MARKET SEGMENT UPDATES (TYPE 3)
								echo '<h4 class="mt-4"><b>Market Segment Updates</b></h4>';
								echo '<table class="table table-bordered table-sm">';
								echo '<thead><tr>
										<th style="width: 10%;">Date</th>
										<th style="width: 10%;">Segment</th>
										<th style="width: 60%;">Comment</th>
										<th style="width: 20%;">Employee</th>
									  </tr></thead><tbody>';
								foreach ($enq['updates'] as $u) {
									if ($u['customer_update_type_id'] == 3) {
										$segmentName = findNameById(
											$customer_market_segment,
											'customer_market_segment_id',
											'name',
											$u['selected_id']
										);
										echo '<tr>';
										echo '<td style="padding: 5px !important;">'.date("d M Y", strtotime($u['createdate'])).'</td>';
										echo '<td style="padding: 5px !important;">'.$segmentName.'</td>';
										echo '<td style="padding: 5px !important;">'.$u['employee_update'].'</td>';
										echo '<td style="padding: 5px !important;">'.$u['name'].'</td>';
										echo '</tr>';
									}
								}
								echo '</tbody></table>';
								// 3️. REQUIREMENT UPDATES (TYPE 4)
								echo '<h4 class="mt-4"><b>Requirements Updates</b></h4>';
								echo '<table class="table table-bordered table-sm">';
								echo '<thead><tr>
										<th style="width: 15%;">Date</th>
										<th style="width: 65%;">Comment</th>
										<th style="width: 20%;">Employee</th>
									  </tr></thead><tbody>';
								foreach ($enq['updates'] as $u) {
									if ($u['customer_update_type_id'] == 4) {
										echo '<tr>';
										echo '<td style="padding: 5px !important;">'.date("d M Y", strtotime($u['createdate'])).'</td>';
										echo '<td style="padding: 5px !important;">'.$u['employee_update'].'</td>';
										echo '<td style="padding: 5px !important;">'.$u['name'].'</td>';
										echo '</tr>';
									}
								}
								echo '</tbody></table>';
								// 4️. ENTER UPDATES SECTION
								echo '<h4 class="mt-4"><b>Enter Updates</b></h4>';
								echo '<table class="table table-bordered" id="update_table_enq_'.$enquiryId.'">';
								echo '<thead>
										<tr>
											<th></th>
											<th style="width: 15%;">Update Type</th>
											<th style="width: 15%;">Value</th>
											<th style="width: 70%;">Comment</th>
										</tr>
									  </thead>
									  <tbody></tbody>
									  </table>';
								echo '<button onclick="addUpdateRow(\'update_table_enq_'.$enquiryId.'\','.$enq['customer_enquiry_status_id'].')" style="font-size: 1.5rem;"	class="btn btn-sm btn-primary"> Add Row </button>
									  <button onclick="submit('.$enquiryId.')" style="font-size: 1.5rem;" class="btn btn-sm btn-success"> Update Enquiry </button>';
							echo '</div><hr style="height: 5px;color: black;" /><br>
							</div>';
						?>
						</td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endforeach; ?>
        <!-- Fixed button -->
	</div>
<script>
	var customer_enquiry_status=<?php echo json_encode($customer_enquiry_status); ?>;
    var customer_company=<?php echo json_encode($customer_company); ?>;
    var customer_FIBU_product_type=<?php echo json_encode($customer_FIBU_product_type); ?>;
    var customer_location=<?php echo json_encode($customer_location); ?>;
    var customer_product_category=<?php echo json_encode($customer_product_category); ?>;
    var customer_update_type=<?php echo json_encode($customer_update_type); ?>;
    var customer_market_segment=<?php echo json_encode($customer_market_segment); ?>;
    var customer_category=<?php echo json_encode($customer_category); ?>;
    var customer_enquiry_source=<?php echo json_encode($customer_enquiry_source); ?>;
    var employee_list=<?php echo json_encode($employee_list); ?>;
    var customer_enquiry=<?php echo json_encode($customer_enquiry); ?>;
	$('.tabs a').on('click', function (e) {
        e.preventDefault();
        $('.tab-content').hide();
        $($(this).attr('href')).show();
    });
	$('.tabs a:first').click(); // Open first tab by default
	$('.tabs a:first').addClass('active');
	document.querySelectorAll('.tabs a').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            // Remove active class from all tabs
            document.querySelectorAll('.tabs a').forEach(el => el.classList.remove('active'));
            // Remove active from all tab contents
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            // Add active class to clicked tab and its content
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });
	function showDetails(ele,row){
		if ($("#"+ele).css('display') === 'none') {
			$(".details_section").css('display', 'none');
			$("#"+ele).css('display', 'block');
			//$(row).css('background-color', '#e9ecef');
			$("#"+ele+' .select2').select2({
				placeholder: "Select",
				allowClear: true,
				width: '100%'
			});
		} else {
			$("#"+ele).css('display', 'none');
			$(row).css('background-color', '');
		}
	}
	// ENQUIRY POPUP
	function addEnquiry() {
		const statusOptions = customer_enquiry_status.map(s =>
			`<option value="${s.customer_enquiry_status_id}">${s.name}</option>`
		).join('');
		const updateTypeOptions = customer_update_type.map(u =>
			`<option value="${u.customer_update_type_id}">${u.value}</option>`
		).join('');
		const marketOptions = customer_market_segment.map(m =>
			`<option value="${m.customer_market_segment_id}">${m.name}</option>`
		).join('');
		const sourceOptions = customer_enquiry_source.map(s =>
			`<option value="${s.customer_enquiry_source_id}">${s.name}</option>`
		).join('');
		var updateTableBody="";
		customer_update_type.forEach(u => {
			updateTableBody += `<tr>
				<td style="border: none !important;">
					<select class="updateType swal2-select"  style="display:none;">
						<option value="${u.customer_update_type_id}" selected>${u.value}</option>
					</select><b>${u.value}:</b>
				</td>
				<td class="updateValue" style="display:none;border: none !important;">`;
				// Market
				if (u.customer_update_type_id == 3) {
					updateTableBody +=  `<select id= "marketUpdateSelect" class="swal2-select"><option value="">Select</option>${marketOptions}</select>`;
				}
				// Status → FIXED = 1
				else if (u.customer_update_type_id == 2) {
					updateTableBody +=  `
						<select class="swal2-select" disabled>
							<option value="1" selected>
								${customer_enquiry_status.find(s => s.customer_enquiry_status_id == 1)?.name || 'Open'}
							</option>
						</select>
					`;
				}
			updateTableBody += 	`</td>
				<td style="border: none !important;"><textarea class="updateNote swal2-textarea form-control" style="width: 100%;margin: 0;"></textarea></td>
			</tr>`;
		});
		const contentText = `
		<div style="text-align:left; font-size: 1.5rem;">
			<h3><b>Company Details</b></h3><hr/>
			<div class="row"><div class="col-md-2"><label><b>Company:</b></label></div>
			<div class="col-md-6"><select id="companySelect" class="swal2-select">
				<option value="">Select</option>
				<option value="new">+ Add New Company</option>
				${buildCompanyOptions()}
			</select></div></div>
			<div id="newCompanyForm" style="display:none; margin-top:10px;"></div><br>
			<div class="row"><div class="col-md-2"><label><b>Contact Person</b></label></div>
			<div class="col-md-6"><select id="contactSelect" class="swal2-select">
				<option value="">Select</option>
				<option value="new">+ Add New Contact</option>
			</select></div></div>
			<div id="newContacts" style="margin-top: 10px;"></div>
			<br><br>
			<h3><b>Enquiry Details</b></h3><hr/>
			<table border="0" width="100%" id="updateTable" style="border-collapse: collapse; border: none;">
				<thead>
					<tr>
						<th style="width: 10%;border: none !important;">Add Note For:</th>
						<th style="display:none;">Value</th>
						<th style="width: 90%;border: none !important;"></th>
					</tr>
				</thead>
				<tbody>${updateTableBody}</tbody>
			</table>
			<div class="row" style="margin-top: 10px;">
				<div class="col-md-2">
					<label class="form-label">Status:</label>
					<select id="statusSelect" class="swal2-select form-control" disabled>
						<option value="1" selected>
							${customer_enquiry_status.find(s => s.customer_enquiry_status_id == 1)?.name || 'Suspect'}
						</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label">Source:</label>
					<select id="sourceSelect" class="swal2-select form-control">
						<option value="">Select</option>
						${sourceOptions}
					</select>
				</div>
				<div class="col-md-6">
					<label class="form-label">Note Regarding Source:</label>
					<textarea id="sourceNote" class="swal2-textarea form-control" style="margin: 0;"></textarea>
				</div>
				<div class="col-md-2">
					<label class="form-label">Estimated Cost:</label>
					<input type="number" id="estimatedCost" class="swal2-input form-control" style="margin: 0;" />
				</div>
			</div>
		</div>`;
		Swal.fire({
			title: "Add Enquiry",
			width: "80%",
			html: contentText,
			confirmButtonText: "Submit Enquiry",
			showCancelButton: true,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#28a745",
			heightAuto: false,
			didOpen: () => {
				const popup = Swal.getPopup();
				popup.style.height = "90vh";
				popup.style.display = "flex";
				popup.style.flexDirection = "column";
				// make content scrollable
				const htmlContainer = popup.querySelector('.swal2-html-container');
				htmlContainer.style.overflow = "auto";
				htmlContainer.style.flex = "1";
				// Apply select2
				$('#companySelect, #contactSelect, #statusSelect, #productCategory, #fibuType, #sourceSelect')
					.select2({ dropdownParent: $('.swal2-container') });
				// COMPANY CHANGE
				$('#companySelect').on('change', function () {
					const val = this.value;
					$('#newContacts').html("");
					if (!val) return;
					// show company form (new OR edit)
					buildCompanyForm(val);
					// rebuild contacts dropdown
					$('#contactSelect').html(buildContactOptions(val));
					if (val === "new") {
						$('#contactSelect').val("new").trigger('change');
					} else {
						$('#contactSelect').trigger('change');
					}
					syncMarketFromCompany();
				});
				// CONTACT CHANGE
				$('#contactSelect').on('change', function () {
					const companyId = $('#companySelect').val();
					if (!companyId) return;
					buildContactForm(companyId, this.value);
				});
			},
			preConfirm: () => {
				const data = {
					customer_company_id: $('#companySelect').val(),
					company_details: null,
					customer_company_department_id: $('#contactSelect').val(),
					contact_details: [],
					customer_enquiry_status_id: document.getElementById("statusSelect").value,
					updates: [],
					customer_enquiry_source_id: $('#sourceSelect').val(),
					source_note: $('#sourceNote').val(),
					estimated_cost: parseFloat($('#estimatedCost').val()) || 0,
				};
				// new company
				//if (data.company === "new") {
					data.company_details = {};
					$('#newCompanyForm').find('input, textarea, select').each(function () {
						if (!this.name) return;
						// multi select
						if (this.multiple) {
							data.company_details[this.name] = [...this.selectedOptions].map(o => o.value);
						} 
						// single
						else {
							data.company_details[this.name] = this.value;
						}
					});
				//}
				// new contacts
				data.contact_details = {};
				$('#newContacts .contactBlock').each(function () {
					$(this).find('input, textarea, select').each(function () {
						if (!this.name) return;
						if (this.multiple) {
							data.contact_details[this.name] = [...this.selectedOptions].map(o => parseInt(o.value, 10)).join(',');
						} else {
							data.contact_details[this.name] = this.value;
						}
					});
				});
				document.querySelectorAll("#updateTable tbody tr").forEach(tr => {
					data.updates.push({
						customer_update_type_id: tr.querySelector(".updateType").value,
						selected_id: tr.querySelector(".updateValue select")?.value || 0,
						employee_update: tr.querySelector(".updateNote").value
					});
				});
				console.log("FINAL DATA", data);
				//return data;
				$.ajax({
					url: "scripts/URLServerConnect",
					type: "POST",
					crossDomain: true,
					data: {alldata:data,targetpage:'Projects',funCall:'postEnquiryAdd'},
					xhrFields: {withCredentials: true},
					success: function (response) {console.log(response);response=JSON.parse(response);console.log(response);
						if(response['status'] == "success"){
							Swal.fire({
								icon: 'success',
								title: 'Enquiry entered successfully!',
								showConfirmButton: false,
								timer: 1500
							}).then(() => {
								location.reload();
							});
							/*Swal.fire({
								icon: 'success',
								title: 'Success',
								text: 'Enquiry entered successfully!',
								confirmButtonColor: '#28a745'
							}).then(() => {
								location.reload();
							});*/
						}else {
							Swal.fire({
								icon: 'error',
								title: 'Failed',
								text: 'Enquiry not entered. Please try again.',
								confirmButtonColor: '#dc3545'
							});
						}
					},
					error: function (xhr, status) {
						console.log(status);
						if (navigator.onLine==true) {
							alert('Something went wrong. retry again.');
						} else {
							alert('No internet connection.');
						}
					}
				});
			}
		});
	}
	function buildCompanyOptions() {
		return customer_company
			.map(c => `<option value="${c.customer_company_id}">${c.name}</option>`)
			.join('');
	}
	function buildContactOptions(companyId) {
		let html = `
			<option value="">Select</option>
			<option value="new">+ Add New Contact</option>`;
		const company = customer_company.find(c => c.customer_company_id == companyId);
		if (company && company.contact_person) {
			html += company.contact_person
				.map(p => `<option value="${p.customer_company_department_id}">
							${p.contact_person}
						  </option>`)
				.join('');
		}
		return html;
	}
	function buildCompanyForm(companyId = null) {
		let data = null;
		if (companyId && companyId !== "new") {
			data = customer_company.find(c => c.customer_company_id == companyId);
		}
		// if new → use template from first object
		if (!data && customer_company.length) {
			data = JSON.parse(JSON.stringify(customer_company[0]));
			for (let k in data) {
				if (typeof data[k] !== "object") data[k] = "";
			}
		}
		let html = `<div class="container-fluid" style="font-size: 1.5rem;">`;
		let colCount = 0;
		for (let key in data) {
			if (
				key == "customer_company_id" ||
				key == "created_by" ||
				key == "createdate" ||
				key == "qualified_marketing_lead" ||
				key == "updatedate" ||
				key == "assigned_employees_id" ||
				key == "customer_marketing_lead_status_id" ||
				key == "contact_person"
			) continue;
			// new row every 3 columns
			if (colCount % 3 === 0) html += `<div class="row">`;
			html += `<div class="col-md-4 mb-3">`;
			// DROPDOWNS
			if (key == "customer_category_id") {
				// LABEL
				html += `<label class="form-label">Customer Category:</label>`;
				const options = customer_category.map(s =>
					`<option value="${s.customer_category_id}"
						${data[key] == s.customer_category_id ? "selected" : ""}>
						${s.name}
					</option>`
				).join('');
				html += `
					<select id="${key}" name="${key}" class="swal2-select form-control">
						<option value="">Select</option>
						${options}
					</select>`;
			}
			else if (key == "customer_market_segment_id") {
				// LABEL
				html += `<label class="form-label">Market Segment:</label>`;
				const options = customer_market_segment.map(s =>
					`<option value="${s.customer_market_segment_id}"
						${data[key] == s.customer_market_segment_id ? "selected" : ""}>
						${s.name}
					</option>`
				).join('');
				html += `
					<select id="${key}" name="${key}" class="swal2-select form-control" onchange="syncMarketFromCompany()">
						<option value="">Select</option>
						${options}
					</select>`;
			}
			else if (key == "customer_location_id") {
				html += `<label class="form-label">State:</label>`;
				const options = customer_location.map(s =>
					`<option value="${s.customer_location_id}"
						${data[key] == s.customer_location_id ? "selected" : ""}>
						${s.country}: ${s.state}
					</option>`
				).join('');
				html += `
					<select id="${key}" name="${key}" class="swal2-select form-control">
						<option value="">Select</option>
						${options}
					</select>`;
			}
			else {
				// LABEL
				html += `<label class="form-label">${key.replaceAll('_',' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase())}:</label>`;
				html += `<input 
							class="swal2-input form-control" 
							name="${key}" id="${key}" 
							value="${data[key] ?? ''}" 
							style="padding: 0;margin: 0; font-size: 1.3rem;" 
						/>`;
			}

			html += `</div>`; // col

			// close row
			if (colCount % 3 === 2) html += `</div>`;

			colCount++;
		}

		// close last row if not closed
		if (colCount % 3 !== 0) html += `</div>`;

		html += `</div>`;
		$('#newCompanyForm').html(html).show();
		$('#newCompanyForm select').select2({
			dropdownParent: $('.swal2-container')
		});
	}
	function buildContactForm(companyId, contactId = null) {
		let company = customer_company.find(c => c.customer_company_id == companyId);
		if (!company && contactId !== "new") return;
		let data = null;
		if (contactId && contactId !== "new") {
			data = company.contact_person.find(p => p.customer_company_department_id == contactId);
		}
		// template
		if (!data) {
			let sample = null;
			for (let c of customer_company) {
				if (c.contact_person && c.contact_person.length) {
					sample = c.contact_person[0];
					break;
				}
			}
			data = sample ? JSON.parse(JSON.stringify(sample)) : {};
			for (let k in data) data[k] = "";
		}
		let html = `<div class="contactBlock container-fluid" style="font-size: 1.5rem;">`;
		let colCount = 0;
		for (let key in data) {
			if (
				key == "customer_company_department_id" ||
				key == "customer_company_id" ||
				key == "created_by" ||
				key == "createdate" ||
				key == "updatedate"
			) continue;
			if (colCount % 3 === 0) html += `<div class="row">`;
			html += `<div class="col-md-4 mb-3">`;
			if (key == "customer_FIBU_product_type") {
				let selectedValues = data[key] ? data[key].toString().split(',') : [];
				html += `<label class="form-label">FIBU Product Type:</label>`;
				const options = customer_FIBU_product_type.map(s =>
					`<option value="${s.customer_FIBU_product_type_id}"
						${selectedValues.includes(String(s.customer_FIBU_product_type_id)) ? "selected" : ""}>
						${s.name}
					</option>`
				).join('');
				html += `<select multiple id="${key}" name="${key}" class="swal2-select form-control">${options}</select>`;
			}
			else if (key == "customer_product_category") {
				let selectedValues = data[key] ? data[key].toString().split(',') : [];
				html += `<label class="form-label">Product Categories:</label>`;
				const options = customer_product_category.map(s =>
					`<option value="${s.customer_product_category_id}"
						${selectedValues.includes(String(s.customer_product_category_id)) ? "selected" : ""}>
						${s.name}
					</option>`
				).join('');
				html += `<select multiple id="${key}" name="${key}" class="swal2-select form-control">${options}</select>`;
			}
			else {
				if(key=="name")
					html += `<label class="form-label">Department Name:</label>`;
				else
					html += `<label class="form-label">${key.replaceAll('_',' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase())}:</label>`;
				html += `
					<input 
						class="swal2-input form-control"
						name="${key}" id="${key}"
						value="${data[key] ?? ''}" style="padding: 0;margin: 0; font-size: 1.3rem;"
					/>`;
			}
			html += `</div>`;
			if (colCount % 3 === 2) html += `</div>`;
			colCount++;
		}
		if (colCount % 3 !== 0) html += `</div>`;
		html += `</div>`;
		$('#newContacts').html(html);
		$('#newContacts select').select2({
			dropdownParent: $('.swal2-container')
		});
	}
	function syncMarketFromCompany() {
		const val = $('#customer_market_segment_id').val();
		$('#marketUpdateSelect').val(val).trigger('change');
	}
	/* ADD ROW */
	function addUpdateRow(tableId, enquiry_status_id){
		var typeOptions = '<option value="">Select</option>';
		customer_update_type.forEach(function(type){
			typeOptions += '<option value="'+type.customer_update_type_id+'">' + type.value + '</option>';
		});
		var row = `
		<tr>
			<td>
				<button type="button" class="btn btn-sm btn-danger removeRow" style="font-size: 1.5rem;padding: .375rem .75rem;">Remove</button>
			</td>
			<td>
				<select class="form-control updateType1" data-status-id="`+enquiry_status_id+`" style="font-size: 1.5rem;">
					${typeOptions}
				</select>
			</td>
			<td>
				<select class="form-control updateValue" style="font-size: 1.5rem;">
					<option value="">Select</option>
				</select>
			</td>
			<td>
				<textarea class="form-control" style="font-size: 1.5rem; height: 33px;"></textarea>
			</td>
		</tr>`;
		$('#'+tableId+' tbody').append(row);
	}
	/* REMOVE ROW */
	$(document).on('click', '.removeRow', function(){
		$(this).closest('tr').remove();
	});
	/* UPDATE VALUE DROPDOWN BASED ON TYPE */
	$(document).on('change', '.updateType1', function(){
		var valueSelect = $(this).closest('tr').find('.updateValue');
		var statusLimit = parseInt($(this).data('status-id'));
		var typeId = $(this).val();
		valueSelect.empty();
		if(typeId == "2"){   // Status
			valueSelect.append('<option value="">Select</option>');
			customer_enquiry_status.forEach(function(status){
				if(parseInt(status.customer_enquiry_status_id) <= statusLimit){
					valueSelect.append(
						'<option value="'+status.customer_enquiry_status_id+'">' + status.name +	'</option>'
					);
				}
			});
		}
		else if(typeId == "3"){  // Market Segment
			valueSelect.append('<option value="">Select</option>');
			customer_market_segment.forEach(function(segment){
				valueSelect.append(
					'<option value="'+segment.customer_market_segment_id+'">' + segment.name + '</option>'
				);
			});
		}
		else if(typeId == "4"){  // Requirement
			valueSelect.append('<option value="0" selected>NA</option>');
		}
		else{
			valueSelect.append('<option value="">Select</option>');
		}
	});
	function submit(enq_id){
		/* 1️.CUSTOMER COMPANY DATA */
		var customer_company = {};
		$('#customer_company'+enq_id)
			.find('input, select, textarea')
			.each(function(){
				var key = $(this).attr('id');
				if(!key) return;
				if($(this).is('select[multiple]')){
					customer_company[key] = $(this).val().join(',') || '';
				}else{
					customer_company[key] = $(this).val();
				}
			});
		/* 2️. CONTACT PERSON DATA */
		var contact_person = {};
		$('#contact_person'+enq_id)
			.find('input, select, textarea')
			.each(function(){
				var key = $(this).attr('id');
				if(!key) return;
				if($(this).is('select[multiple]')){
					contact_person[key] = $(this).val().join(',') || '';
				}else{
					contact_person[key] = $(this).val();
				}
			});
		/* 3️. STAGES TABLE DATA */
		var stages = [];
		var parentToHandle = new Set();   // prevents duplicates 
		var table = $('#customer_enquiry_stages'+enq_id);
		// Get Rating row
		var ratingRow = table.find('tbody tr').filter(function(){
			return $(this).find('td:first').text().trim() === "Rating";
		});
		// Get Status row
		var statusRow = table.find('tbody tr').filter(function(){
			return $(this).find('td:first').text().trim() === "Status";
		});
		// Count total columns (excluding first label column)
		var totalCols = ratingRow.find('td').length;
		for(var i = 1; i < totalCols; i++){
			var ratingSelect = ratingRow.find('td:eq('+i+') select');
			var statusSelect = statusRow.find('td:eq('+i+') select');
			if(ratingSelect.length){
				var valueId = ratingSelect.attr('customer_enquiry_stages_values_id');
				var rating = parseFloat(ratingSelect.val()) || 0;
				var status = parseInt(statusSelect.val()) || 0;
				stages.push({
					customer_enquiry_stages_values_id : valueId,
					rating : rating,
					status : status
				});
				var parentId = ratingSelect.attr('parentValueId');
				if(parentId){
					parentToHandle.add(parentId);
				}
			}
		}
		/* ===== HANDLE PARENT CALCULATIONS ===== */
		parentToHandle.forEach(parent_id => {
			var total = 0;
			var count = 0;
			$('.rating_parent_'+parent_id).each(function(){
				total += parseFloat($(this).val()) || 0;
				count++;
			});
			 var avg = count > 0 ? Math.round(total / count) : 0;
			var allCompleted = 1;
			$('.status_parent_'+parent_id).each(function(){
				if(parseInt($(this).val()) !== 1){
					allCompleted = 0;
				}
			});
			stages.push({
				customer_enquiry_stages_values_id : parent_id,
				rating : avg,
				status : allCompleted
			});
		});
		/* 4️. OTHER DETAILS */
		var other_details = {};
		other_details['customer_enquiry_id'] = enq_id;
		$('#other_details'+enq_id)
			.find('input, select, textarea')
			.each(function(){
				var key = $(this).attr('id');
				if(!key) return;
				other_details[key] = $(this).val();
			});
		/* 5️. UPDATE TABLE DATA */
		var updates = [];
		$('#update_table_enq_'+enq_id+' tbody tr').each(function(){
			var typeId  = $(this).find('td:eq(1) select').val();
			var selectedId = $(this).find('td:eq(2) select').val();
			var comment = $(this).find('td:eq(3) textarea').val();
			if(typeId && selectedId && comment){  // ignore empty rows
				updates.push({
					customer_enquiry_id : enq_id,
					customer_update_type_id : typeId,
					selected_id : selectedId,
					employee_update : comment
				});
			}
		});
		/* FINAL OBJECT */
		var finalData = {
			customer_company : customer_company,
			contact_person   : contact_person,
			enquiry_stages   : stages,
			enquiry_details  : other_details,
			enquiry_updates  : updates
		};
		console.log(finalData);
		/*
		$.ajax({
			url: "scripts/URLServerConnect",
			type: "POST",
			crossDomain: true,
			data: {alldata:finalData,targetpage:'Projects',funCall:'postEnquiryEdit'},
			xhrFields: {withCredentials: true},
			success: function (response) {console.log(response);response=JSON.parse(response);console.log(response);
				if(response['status'] == "success"){
					Swal.fire({
						icon: 'success',
						title: 'Enquiry Updated successfully!',
						showConfirmButton: false,
						timer: 1500
					}).then(() => {
						//location.reload();
					});
				}else {
					Swal.fire({
						icon: 'error',
						title: 'Failed',
						text: 'Enquiry is not updated. Please try again.',
						confirmButtonColor: '#dc3545'
					});
				}
			},
			error: function (xhr, status) {
				console.log(status);
				if (navigator.onLine==true) {
					alert('Something went wrong. retry again.');
				} else {
					alert('No internet connection.');
				}
			}
		});
		*/
	}
</script>
</body>
</html>
