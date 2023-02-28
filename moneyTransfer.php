<?php
$notifyStatus = 0;

// HANDLING POST ACTIONS START
if (isset($_POST['action'])) {
  session_start();
  $user_id = $_SESSION['user_id'];
  $customer_id = $_SESSION['customer_id'];
  include('config.php');
  include('stripe_config.php');
  header("Content-type: application/json");
  $action = $_POST['action'];
  $respoArr = ["status" => 500, "message" => "Some Error Occured"];
  try {
    $fetchUserQuery = "SELECT commission_setting from str_users where id=?";
    $stmt = $connection->prepare($fetchUserQuery);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $queryResult = $stmt->get_result();
    $userData = $queryResult->fetch_assoc();
    $userComission = $userData['commission_setting'];
  } catch (Exception $e) {
    die("Some Error Occured");
  }
  $stripeCharges = 0;
  $platformCharge = 0;
  $maxLimit = 0;
  try {
    $fetchCommissionQuery = "SELECT setting From str_adminSettings";
    $stmt = $connection->prepare($fetchCommissionQuery);
    $stmt->execute();
    $commissionResult = $stmt->get_result();
    $commission = $commissionResult->fetch_assoc();
    $commissionArr = json_decode($commission['setting'], true);
    $stripeCharges = (float)$commissionArr['stripeCharge'];
    $platformCharge = (float)$commissionArr['commission'];
    $maxLimit = (float)$commissionArr['maximumTransferLimit'];
    try {
      if ($userComission != "") {
        $newComission = json_decode($userComission, true);
        $platformCharge = $newComission['commission'];
        $maxLimit = $newComission['maximumTransferLimit'];
      }
      if ($action == "transferMoney") {
        $_SESSION['transferData'] = $_POST;
        $transferDest = $_POST['transferDest'];
        $transferBankId = $_POST['transferBankId'];
        $transferStripeIdDest = $_POST['transferStripeIdDest'];
        $transferAmt = (float)$_POST['transferAmt'];
        unset($_POST['action']);
        $platformCharge = (float)(($transferAmt * $platformCharge) / 100);
        $totalAmount = (float)($transferAmt + $platformCharge);
        $stripeFees = (float)((($totalAmount * $stripeCharges) / 100) + 2.35);
        $totalAmount += $stripeFees;
        $totalAmount = (float)(round($totalAmount, 2) * 100);
        $transfer_json = json_encode($_POST);
        try {
          $checkoutData = $stripe->checkout->sessions->create([
            'customer' => $customer_id,
            'payment_method_types' => ['card'],
            'success_url' => $basePath . '/moneyTransfer.php?action=verifyTransaction&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $basePath . '/moneyTransfer.php?action=verifyTransaction&session_id={CHECKOUT_SESSION_ID}',
            'line_items' => [[
              'price_data' => [
                'currency' => 'HKD',
                'product_data' => [
                  'name' => 'Datasunday',
                ],
                'unit_amount' => $totalAmount,
              ],
              'quantity' => 1
            ]],
            'payment_intent_data' => [
              'setup_future_usage' => 'off_session'
            ],
            'mode' => 'payment'
          ]);
          $checkoutJson = json_encode($checkoutData);
          try {
            $insertPaymentQuery = "INSERT into str_cardPayments(user_id_fk,transfer_json,check_sess_id,checkout_json) VALUES(?,?,?,?)";
            $stmt = $connection->prepare($insertPaymentQuery);
            $stmt->bind_param("isss", $user_id, $transfer_json, $checkoutData->id, $checkoutJson);
            $result = $stmt->execute();
          } catch (Exception $e) {
          }
          $respoArr["redirectURL"] = $checkoutData->url;
        } catch (Exception $e) {
          $respoArr["message"] = $e->getMessage();
        }
      }
    } catch (Exception $e) {
      $respoArr["message"] = $e->getMessage();
    }
  } catch (\Throwable $th) {
    $respoArr["message"] = $th->getMessage();
  }
  die(json_encode($respoArr));
}
// HANDLING POST ACTIONS END

// HANDLING GET ACTIONS START
if (isset($_GET['action'])) {
  session_start();
  $user_id = $_SESSION['user_id'];
  $customer_id = $_SESSION['customer_id'];
  include('config.php');
  include('stripe_config.php');
  header("Content-type: application/json");
  $action = $_GET['action'];
  $respoArr = ["data" => []];
  if ($action == "fetchTransactions") {
    try {
      $fetchTransactionsQuery = "SELECT * FROM str_cardPayments WHERE user_id_fk = ? and payment_json IS NOT NULL ORDER BY payment_date desc";
      $stmt = $connection->prepare($fetchTransactionsQuery);
      $stmt->bind_param("i", $user_id);
      $stmt->execute();
      $paymentResult = $stmt->get_result();
      while ($paymentData = $paymentResult->fetch_assoc()) {
        $transferData = json_decode($paymentData['transfer_json'], true);
        $paymentDate = $paymentData['payment_date'];
        $transferStatus = ($paymentData['status'] == 0) ? "Pending" : "Transferred";
        $paymentData = json_decode($paymentData['payment_json'], true);
        $paymentStatus = ($paymentData['status'] == "open") ? "Incomplete" : $paymentData['status'];
        if ($transferData['transferDest'] == "bank")
          $transactionDesti = "Bank Account - " . $transferData['transferBankId'];
        else {
          $stripeDestiData = explode("-", $transferData['transferStripeIdDest']);
          $transactionDesti = "Stripe Account - " . $stripeDestiData[2];
        }
        $amount = "HK$" . $transferData['transferAmt'];
        $receiptUrl = (isset($paymentData['receiptUrl'])) ? $paymentData['receiptUrl'] : "#";
        $respoArr['data'][] = ["", $transactionDesti, $amount, $transferStatus, $paymentStatus, $paymentDate, $receiptUrl];
      }
    } catch (\Throwable $th) {
      die(json_encode($respoArr));
    }
    die(json_encode($respoArr));
  } else if ($action == "verifyTransaction") {
    header("Content-type: text/html");
    $notifyStatus = 1;
    $respoArr["message"] = "Payment Failed";
    $respoArr["status"] = 500;
    $respoArr["style"] = "danger";
    $transferData = $_SESSION['transferData'];
    $paymentDetails = $stripe->checkout->sessions->retrieve($_GET['session_id']);
    $paymentIntentData = $stripe->paymentIntents->retrieve(
      $paymentDetails->payment_intent,
      []
    );
    $paymentDetails["receiptUrl"] = $paymentIntentData->charges->data[0]->receipt_url;
    $paymentJson = json_encode($paymentDetails);
    try {
      $updatePaymentJson = "UPDATE str_cardPayments SET payment_json =?  WHERE check_sess_id=?";
      $stmt = $connection->prepare($updatePaymentJson);
      $stmt->bind_param("ss", $paymentJson, $_GET['session_id']);
      $result = $stmt->execute();
    } catch (Exception $e) {
      $respoArr["message"] = "Some Error Occured";
    }
    if ($paymentDetails->payment_status == 'paid') {
      if ($transferData['transferDest'] == "stripe") {
        try {
          $stripeDestiData = explode("-", $transferData['transferStripeIdDest']);
          $stripeTransfer = $stripe->transfers->create([
            'amount' => (float)(round($transferData['transferAmt'], 2) * 100),
            'currency' => 'HKD',
            'destination' => $stripeDestiData[2],
            'transfer_group' => 'ORDER_95',
          ]);
          $payStatus = 1;
          $respoArr = ["status" => 2001, "message" => "Money Transfer Successful It will reflect in your account shortly."];
          $updatePaymentJson = "UPDATE str_cardPayments SET status =?  WHERE check_sess_id=?";
          $stmt = $connection->prepare($updatePaymentJson);
          $stmt->bind_param("is", $payStatus, $_GET['session_id']);
          $result = $stmt->execute();
        } catch (\Throwable $th) {
          $errorCode = $th->getError()->code;
          if ($errorCode == "transfers_not_allowed")
            $respoArr = ["status" => 200, "message" => "Money Transfer from card is Successful but Stripe Transfer is not Allowed in your region Please contact support."];
          else if ($errorCode == "balance_insufficient")
            $respoArr = ["status" => 200, "message" => "Money Transfer from card is Successful but Stripe Transfer has some issues due to low balance in Source Account, Please contact support."];
          else
            $respoArr["message"] = $th->getMessage() . " " . $th->getError()->code;
        }
      } else
        $respoArr = ["status" => 200, "message" => "Money Transfer Successful It will reflect in your account shortly."];
    } else
      $respoArr["message"] = "Payment Failed";
  }
}
// HANDLING POST ACTIONS END

// PAGE VARS CONFIG START
$pageTitle = "Money Transfer";
$headContent = '<link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/dataTables.bootstrap4.min.css"><link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.7.0/css/buttons.dataTables.min.css"><link rel="stylesheet" href="https://cdn.datatables.net/select/1.3.3/css/select.dataTables.min.css"/><style>#viewApiData thead th.sorting{vertical-align: middle;padding: 0.5rem !important;}#newTransfer { float: right; margin-right: 12px; }.modal-dialog.modal-dialog-centered { height: 541px; display: block; overflow: hidden; width: 100%; } </style>';
require __DIR__ . '/layouts/header.php';
// PAGE VARS CONFIG END

// FETCH ALL BANK ACCOUNTS AND CREDIT CARDS START
$user_id = $_SESSION['user_id'];
try {
  $fetchUserQuery = "SELECT str_card_json,bank_json,commission_setting from str_users where id=?";
  $stmt = $connection->prepare($fetchUserQuery);
  $stmt->bind_param("s", $user_id);
  $stmt->execute();
  $queryResult = $stmt->get_result();
  $userData = $queryResult->fetch_assoc();
  $allBanks = json_decode($userData['bank_json'], true);
  $allCards = json_decode($userData['str_card_json'], true);
  $userComission = $userData['commission_setting'];
} catch (Exception $e) {
  die("Some Error Occured");
}
// FETCH ALL BANK ACCOUNTS AND CREDIT CARDS END

// FETCH ALL LINKED STRIPE ACCOUNTS START
try {
  $stripeFetchQuery = "SELECT account_json FROM str_stripeAccount WHERE user_id_fk =?";
  $stmt = $connection->prepare($stripeFetchQuery);
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
} catch (\Throwable $th) {
  die("Some Error Occured");
}
// FETCH ALL LINKED STRIPE ACCOUNTS END

// FETCH COMMISSION AND OTHER CHARGES START
$stripeCharges = "0";
$platformCharge = "0";
$maxLimit = "0";
try {
  $fetchCommissionQuery = "SELECT setting From str_adminSettings";
  $stmt = $connection->prepare($fetchCommissionQuery);
  $stmt->execute();
  $commissionResult = $stmt->get_result();
  $commission = $commissionResult->fetch_assoc();
  $commissionArr = json_decode($commission['setting'], true);
  $stripeCharges = $commissionArr['stripeCharge'];
  $platformCharge = $commissionArr['commission'];
  $maxLimit = $commissionArr['maximumTransferLimit'];
} catch (\Throwable $th) {
  die("Some Error Occured");
}
if ($userComission != "") {
  $newComission = json_decode($userComission, true);
  $platformCharge = $newComission['commission'];
  $maxLimit = $newComission['maximumTransferLimit'];
}
// FETCH COMMISSION AND OTHER CHARGES END

?>
<body class="g-sidenav-show bg-gray-200">
  <?php require __DIR__ . '/layouts/sidenav1.php'; ?>
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg ">
    <?php require __DIR__ . '/layouts/topbar.php'; ?>

    <!-- TRANSFER MONEY MODAL START -->
    <div class="modal fade" id="transferMoneyModal" tabindex="-1" role="dialog" aria-labelledby="transferMoneyModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title font-weight-normal" id="transferMoneyModalLabel">Create New Transfer</h5>
          </div>
          <div class="modal-body">
            <div class="mt-4 mb-4">
              <div class="p-0 position-relative mt-n4 mx-3 z-index-2">
                <div class="bg-gradient-info shadow-info border-radius-lg pt-4 pb-3">
                  <div class="multisteps-form__progress">
                    <button class="multisteps-form__progress-btn js-active" type="button" title="step1">
                      <span>Step:1</span>
                    </button>
                    <button class="multisteps-form__progress-btn" type="button" title="step2">Step:2</button>
                  </div>
                </div>
              </div>
            </div>
            <form method="POST" action="" style="position: relative;height: 336px;" class="multisteps-form__form">
              <div class="multisteps-form__panel border-radius-xl bg-white js-active" data-animation="FadeIn">
                <div class="multisteps-form__content">
                  <div class="input-group input-group-outline my-3 is-filled">
                    <label for="transferDest" class="form-label">Destination</label>
                    <select class="form-control hideChild" id="transferDest" name="transferDest">
                      <option value="stripe">Stripe</option>
                      <option value="bank">Bank Account</option>
                    </select>
                  </div>
                  <div class="input-group input-group-outline my-3 is-filled" id="linkedBankAccounts" style="display:none;">
                    <label for="transferBankId" class="form-label">Select Bank Account</label>
                    <select class="form-control" id="transferBankId" name="transferBankId">
                      <?php
                      foreach ($allBanks as $key => $val)
                        echo "<option value='" . $val['ac_no'] . "'>" . $val['ac_holder_name'] . " - " . $val['ac_no'] . "</option>";
                      ?>
                    </select>
                  </div>
                  <?php if (mysqli_num_rows($result) > 0) { ?>
                    <div class="input-group input-group-outline my-3 is-filled" id="linkedStripeAccountsDest">
                      <label for="transferStripeIdDest" class="form-label">Select Stripe Account</label>
                      <select class="form-control" id="transferStripeIdDest" name="transferStripeIdDest">
                        <?php
                        while ($stripeAccData = mysqli_fetch_assoc($result)) {
                          $linkedAccData = json_decode($stripeAccData['account_json'], true);
                          echo "<option value='" . $linkedAccData['default_currency'] . "-" . $linkedAccData['country'] . "-" . $linkedAccData['id'] . "'>" . $linkedAccData['business_profile']['name'] . " - " . $linkedAccData['id'] . "</option>";
                        }
                        ?>
                      </select>
                    </div>
                  <?php } else { ?>
                    <div id="stripeMass">
                      <p style="font-size: 14px;color: red;font-weight: 500;">There is no Stripe Account, So please Link Your account</p>
                    </div>
                  <?php } ?>
                  <div class="input-group input-group-outline my-3">
                    <label class="form-label">Transfer Amount</label>
                    <input type="number" name="transferAmt" id="transferAmt" min="4" onkeydown="calcTotal()" onkeyup="calcTotal()" class="form-control" max="<?= $maxLimit ?>">
                  </div>
                  <div class="row">
                    <div class="col-sm-6">
                      <span class="font-weight-bold">Platform Charges</span>
                    </div>
                    <div class="col-sm-6">
                      <span id="platformCharges">HK$0.00</span>
                    </div>
                    <div class="col-sm-6">
                      <span class="font-weight-bold">Stripe Fees</span>
                    </div>
                    <div class="col-sm-6">
                      <span id="stripeFees">HK$0.00</span>
                    </div>
                    <div class="col-sm-6">
                      <span class="font-weight-bold">Total Payable Amount</span>
                    </div>
                    <div class="col-sm-6">
                      <span id="totalAmount">HK$0.00</span>
                    </div>
                  </div>
                  <div class="button-row mt-4" style="float: right;">
                    <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn bg-gradient-dark ms-auto js-btn-next" type="button" title="Next" disabled>Next</button>
                  </div>
                </div>
              </div>
              <div class="multisteps-form__panel border-radius-xl bg-white" data-animation="FadeIn">
                <div class="multisteps-form__content mb-10">
                  <p style="font-size: 21px;padding: 0px 0px 0px 24px;color: black;">Please click on "Confirm Transfer" button to proceed to stripe checkout</p>
                </div>
                <div class="button-row mt-4" style="float:right;">
                  <button class="btn bg-gradient-light js-btn-prev" type="button" title="Prev">Prev</button>
                  <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn bg-gradient-info" id="transferMoneyBut">Confirm Transfer</button>

                </div>
              </div>
          </div>
          </form>
        </div>
      </div>
    </div>
    <!-- TRANSFER MONEY MODAL END -->

    <!-- TRANSFER SUCCESS MODAL START -->
    <div class="modal fade" id="transferSuccessModal" tabindex="-1" role="dialog" aria-labelledby="transferSuccessModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title font-weight-normal" id="transferSuccessModalLabel">Transfer Status</h5>
          </div>
          <div class="modal-body">
            <b>The transfer has been completed. Please login to your Stripe account to check the balance.</b>
          </div>
        </div>
      </div>
    </div>
    <!-- TRANSFER SUCCESS MODAL END -->

    <!-- MONEY TRANSFER LIST START -->
    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card my-4">
            <div class="card-header p-0 position-relative mt-n4 mx-3 z-index-2">
              <div class="bg-gradient-info shadow-info border-radius-lg pt-4 pb-3">
                <h6 class="text-white text-capitalize ps-3" style="text-align-last: justify;"><span style="display: -webkit-inline-box;">Money Transfer</span> <button type="button" class="btn bg-gradient-dark" data-bs-toggle="modal" data-bs-target="#transferMoneyModal" id="newTransfer" data-backdrop="static" data-keyboard="false" data-bs-backdrop="static" data-bs-keyboard="false" style="margin-right: 1%;"><i class="material-icons text-sm">add</i>&nbsp;&nbsp;New Transfer</button></h6>
              </div>
            </div>
            <div class="card-body pb-2">
              <div class="table-responsive pb-2">
                <table id='viewApiData' class="table table-hover" style="overflow: hidden;">
                  <thead>
                    <tr>
                      <th style="text-align: center"><input type="checkbox" id="select_all" /></th>
                      <th class="export">Destination</th>
                      <th class="export">Amount</th>
                      <th class="export">Transfer Status</th>
                      <th class="export">Payment Status</th>
                      <th class="export">Date</th>
                      <th>Receipt</th>
                    </tr>
                  </thead>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- MONEY TRANSFER LIST START -->
  </main>
  <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/1.7.0/js/dataTables.buttons.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
  <script src="https://cdn.datatables.net/buttons/1.7.0/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js"></script>
  <script src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap4.min.js"></script>

  <?php
  require __DIR__ . '/layouts/coreFooter.php';
  require __DIR__ . '/layouts/baseFooter.php';
  ?>
  <script src="<?php echo $basePath ?>/assets/multistep-form.js"></script>
  <script>
    // FETCH TRANSACTIONS START
    $.LoadingOverlay("show", {
      image: "",
      text: "Fetching Transactions Please Wait..."
    });
    let table = $('#viewApiData').DataTable({
      "autoWidth": false,
      "processing": false,
      "deferRender": true,
      "responsive": true,
      "ajax": "?action=fetchTransactions",
      columnDefs: [{
          targets: [6],
          render: function(data, type, row) {
            if (data != "#")
              return '<a href="' + data + '" target="_blank"><i class="material-icons ms-auto text-dark cursor-pointer" data-bs-toggle="tooltip" data-bs-placement="top" title="View Transaction">visibility</i></a>';
            else
              return '<p class="font-weight-bold">NA</p>'
          }
        },
        {
          orderable: false,
          className: 'select-checkbox',
          targets: 0
        }
      ],
      select: {
        style: 'multi',
        selector: 'td:first-child'
      },
      order: [
        [1, 'asc']
      ],
      dom: "<'row'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: [{
        extend: 'collection',
        text: 'Export',
        buttons: [{
            extend: 'excelHtml5',
            exportOptions: {
              columns: '.export'
            }
          },
          {
            extend: 'csvHtml5',
            exportOptions: {
              columns: '.export'
            }
          },
          {
            extend: 'pdfHtml5',
            exportOptions: {
              columns: '.export'
            }
          }
        ]
      }],
      language: {
        searchPlaceholder: "Search Transaction",
        search: "",
        emptyTable: "No Transactions Found",
        zeroRecords: "No Transactions Found",
        paginate: {
          "previous": '<i class="material-icons">arrow_back_ios</i>',
          "next": '<i class="material-icons">arrow_forward_ios</i>'
        }
      },
      initComplete: function(settings, json) {
        $.LoadingOverlay("hide");
        $(".dt-buttons").find("button").removeClass("dt-button buttons-collection").addClass("btn btn-outline-info my-2");
        $("#viewApiData_filter").removeClass("dataTables_filter").addClass("input-group input-group-outline my-2").find(":input").removeClass("form-control-sm").addClass("border border-info");
        $("#viewApiData_filter").find("label").addClass("w-100");
      }
    });
    $(document).ready(function() {
      setTimeout(function() {
        $('.multisteps-form__form').css('height', '336px');
      }, 1000);
    });

    $('#viewApiData').on('click', '#select_all', function() {
      if ($('#select_all:checked').val() === 'on')
        table.rows().select();
      else
        table.rows().deselect();
    });
    // FETCH TRANSACTIONS END

    // DELETE ACCOUNT FUNCTION START
    $(document).on('change', '.hideChild', function() {
      var selectedVal = $(this).val();
      $('#stripeMass').css('display', 'none');
      if (selectedVal == "bank")
        ($("#linkedBankAccounts").show(), $("#linkedStripeAccountsDest").hide())
      if (selectedVal == "stripe") {
        ($("#linkedStripeAccountsDest").show(), $("#linkedBankAccounts").hide())
        $('#stripeMass').css('display', 'block');
      }
      calcTotal();
    });
    // DELETE ACCOUNT FUNCTION END

    // CALCULATE FINAL AMOUNT FUNCTION START
    function calcTotal() {
      setTimeout(
        function() {
          var amount = parseFloat($("#transferAmt").val()),
            stripeCharges = parseFloat("<?= $stripeCharges ?>"),
            platformCharge = parseFloat("<?= $platformCharge ?>"),
            maxLimit = parseFloat("<?= $maxLimit ?>");
          if (amount != "undefined" && amount != "null" && amount != undefined && !isNaN(amount) && amount != "" && amount > 0) {
            if (amount <= maxLimit)
              $("#transferError").hide();
            else
              ($("#transferError").html("You can Transfer Max $" + maxLimit + " only, Please Complete KYC To increase payment transfer limit or <a href='kyc.php' class='text-white'>Please Click Here to complete your KYC and Increase the Transfer Limit </a>", "danger").show());
            var totalAmount = amount + platformCharge;
            if (isNaN(totalAmount) || amount == 0 || amount == 0.00)
              $("#platformCharges, #stripeFees, #totalAmount").text("HK$0.00");
            else {
              platformCharge = (amount * platformCharge) / 100;
              totalAmount = amount + platformCharge;
              stripeFees = ((totalAmount * stripeCharges) / 100) + 2.35;
              totalAmount += parseFloat(stripeFees);
              stripeFees = ((totalAmount * stripeCharges) / 100) + 2.35;
              $("#platformCharges").text("HK$" + platformCharge.toFixed("2"));
              $("#stripeFees").text("HK$" + stripeFees.toFixed("2"));
              $("#totalAmount").text("HK$" + ((isNaN(totalAmount)) ? "0.00" : totalAmount.toFixed("2")));
            }
          } else if (isNaN(amount)) {
            $("#platformCharges, #stripeFees, #totalAmount").text("HK$0.00");
            $("#transferError").hide();
          }
        },
        100);
    }
    $("#transferAmt").bind('change', function() {
      var transferAmount = parseFloat($(this).val());
      if (transferAmount <= 3) {
        showNotify("Minimum Transfer amount is HK$4.", "danger");
        $(".js-btn-next").prop("disabled", true);
      } else
        $(".js-btn-next").prop("disabled", false);
      calcTotal();
    });
    // TRANSFER MONEY FUNCTION START
    $("form").submit(function(event) {
      var formData = $(this).serialize();
      event.preventDefault();
      if ($("#transferDest").val() == "bank") {
        if ($("#transferBankId").val() == "" || $("#transferBankId").val() == null) {
          showNotify("Please select a valid bank account.", "danger");
          return false;
        }
      }
      if ($("#transferDest").val() == "stripe") {
        if ($("#transferStripeIdDest").val() == "" || $("#transferStripeIdDest").val() == null) {
          showNotify("Please select a valid stripe account.", "danger");
          return false;
        }
      }
      if ($('#transferAmt').val() < 4) {
        showNotify("Minimum Transfer Amount is HK$4.", "danger");
        return false;
      }
      $.LoadingOverlay("show", {
        image: "",
        text: "Redirecting to Stripe Please Wait..."
      });
      $.ajax({
        type: "POST",
        url: "",
        data: formData + "&action=transferMoney",
        success: function(data) {
          $.LoadingOverlay("hide");
          if (typeof(data.redirectURL) != 'undefined')
            window.parent.location.href = data.redirectURL;
          else
            showNotify(data.message, "danger");
        },
        error: function() {
          $.LoadingOverlay("hide");
          showNotify("Some Error Occured", "danger");
        }
      });
    });
    // TRANSFER MONEY FUNCTION END

    // VERIFY TRANSACTION START
    <?php if ($notifyStatus == 1) {
      echo "window.history.pushState({}, '', new URL(window.location.origin+window.location.pathname));";
      if ($respoArr["status"] == 500)
        echo 'showNotify("' . $respoArr["message"] . '","danger");';
      else if ($respoArr["status"] == 200)
        echo 'showNotify("' . $respoArr["message"] . '","success");';
      else
        echo 'showNotify("' . $respoArr["message"] . '","success");$("#transferSuccessModal").modal("show");';
    } ?>
    // VERIFY TRANSACTION END
  </script>
</body>

</html>