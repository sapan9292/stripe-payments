<?php 
if(isset($_POST['action'])){
  session_start();
  $user_id = $_SESSION['user_id'];
  $customer_id = $_SESSION['customer_id'];
  include('config.php');
  include('stripe_config.php');
  header("Content-type: application/json");
  $action = $_POST['action'];
  $respoArr = ["status"=>500,"message"=>"Some Error Occured"];
  if($action=="deleteCard"){
    $cardId = $_POST['cardId'];
    try {
      $stripe->customers->deleteSource($customer_id, $cardId, []);
      try {
        $stripeAllCards = $stripe->customers->allSources($customer_id, ['object'=>'card','limit'=>30]);
        $cardsUpdateQuery = "UPDATE str_users SET str_card_json=? where id=?";
        $stmt = $connection->prepare($cardsUpdateQuery);
        $stripeAllCards = json_encode($stripeAllCards);
        $stmt->bind_param("ss",$stripeAllCards,$user_id);
        if($stmt->execute())
          $respoArr = ["status"=>200,"message"=>"Card Deleted Successfully"];
      }
      catch (\Throwable $th) {
        $respoArr["message"] = $th->getMessage();
      }
    } 
    catch (Exception $e) {
      $respoArr["message"] = $e->getMessage();
    } 
  }
  else if($action=="addNewCard"){
    $user_name = $_POST['user_name'];
    $card_no = str_replace(" ","",$_POST['card_no']);
    $ccmonth = $_POST['ccmonth'];
    $ccyear = $_POST['ccyear'];
    $cvv = $_POST['cvv'];
    try{
      $card_token = $stripe->tokens->create(
      ['card'=> [
        'number'=>$card_no,
        'exp_month'=>$ccmonth,
        'exp_year'=>$ccyear,
        'name'=>$user_name,
        "cvc"=>$cvv
        ],
      ]);
      try {
        $card_save = $stripe->customers->createSource($customer_id, ['source'=>$card_token->id]);
        try {
          $stripeAllCards = $stripe->customers->allSources($customer_id, ['object'=>'card','limit'=>30]);
          $cardsUpdateQuery = "UPDATE str_users SET str_card_json=? where id=?";
          $stmt = $connection->prepare($cardsUpdateQuery);
          $stripeAllCards = json_encode($stripeAllCards);
          $stmt->bind_param("ss",$stripeAllCards,$user_id);
          if($stmt->execute())
            $respoArr = ["status"=>200,"message"=>"Card Added Successfully"];
        } catch (\Throwable $th) {
          $respoArr["message"] = $th->getMessage();
        }
      } catch (\Throwable $th) {
        $respoArr["message"] = $th->getMessage()." Reason - ".$th->getError()->decline_code." <a href='https://stripe.com/docs/declines/codes' target='_blank'>Click Here</a> for more Info.";
      }
    }
    catch(Exception $e){
      $respoArr["message"] = $e->getMessage();
    }
  }
  die(json_encode($respoArr));
}

$pageTitle = "Card Details";

require __DIR__.'/layouts/header.php';

// FETCH ALL STORED CARDS START
$user_id = $_SESSION['user_id'];
$allCardsQuery = "SELECT str_customer_json,str_card_json from str_users where id=?";
try{
  $stmt = $connection->prepare($allCardsQuery);
  $stmt->bind_param("s",$user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $userData = $result->fetch_assoc();
  $cardData = json_decode($userData['str_card_json'],true);
  $customer_json = json_decode($userData['str_customer_json'],true);
  $_SESSION['customer_id'] = $customer_json['id'];
}
catch(Exception $e){}
// FETCH ALL STORED CARDS END

$cardLogos = ["Visa"=>"visa.png","Discover"=>"discover.jpg","Diners Club"=>"diners_club.png","American Express"=>"amex.png","MasterCard"=>"mastercard.png","Jcb"=>"jcb.png","JCB"=>"jcb.png","UnionPay"=>"unionpay.png"];
?>
<body class="g-sidenav-show bg-gray-200">
  <?php require __DIR__.'/layouts/sidenav.php'; ?>
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg ">
    <?php require __DIR__.'/layouts/topbar.php'; ?>

    <!-- NEW CREDIT CARD ADD MODAL START -->
    <div class="modal fade" id="creditCardModal" tabindex="-1" role="dialog" aria-labelledby="creditCardModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title font-weight-normal" id="creditCardModalLabel">Add New Card</h5>
          </div>
          <form method="POST" action="">
            <div class="modal-body">
              <div class="input-group input-group-outline my-3">
                <label class="form-label">Name</label>
                <input type="text" name="user_name" id="user_name" class="form-control" required>
              </div>
              <div class="row">
                <div class="form-group col-sm-9">
                  <div class="input-group input-group-outline my-1">
                    <label class="form-label">Credit Card Number</label>
                    <input type="tel" name="card_no" id="card_no" class="form-control" required>
                  </div>
                </div>
                <div class="form-group col-sm-3">
                  <div class="input-group input-group-outline my-1">
                    <label id="card_brand" class="form-label font-weight-bold text-info h6"></label>
                  </div>
                </div>
              </div>
              <div class="row mt-1">
                <div class="form-group col-sm-4">
                  <div class="input-group input-group-outline my-3 is-filled">
                    <label class="form-label">Month</label>
                    <select class="form-control" id="ccmonth" name="ccmonth">
                      <?php
                        for($i=1;$i<=12;$i++)
                          echo "<option value='$i'>$i</option>";
                      ?>
                    </select>
                  </div>
                </div>
                <div class="form-group col-sm-4">
                  <div class="input-group input-group-outline my-3 is-filled">
                    <label class="form-label">Year</label>
                    <select class="form-control" id="ccyear" name="ccyear">
                      <?php
                        $date = date("Y");
                        for($j=$date;$j<=$date+20;$j++)
                          echo "<option value='$j'>$j</option>";
                      ?>
                    </select>
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="input-group input-group-outline my-3">
                    <label class="form-label">CVV/CVC</label>
                    <input type="number" name="cvv" id="cvv" class="form-control" required>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn bg-gradient-info">Add Card</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <!-- NEW CREDIT CARD ADD MODAL END -->

    <!-- CARDS LIST START -->
    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card my-4">
            <div class="card-header p-0 position-relative mt-n4 mx-3 z-index-2">
              <div class="bg-gradient-info shadow-info border-radius-lg pt-4 pb-3">
                <h6 class="text-white text-capitalize ps-3" style="text-align-last: justify;"><span style="display: -webkit-inline-box;">Cards Added</span>
                <button type="button" class="btn bg-gradient-dark mb-0" data-bs-toggle="modal" data-bs-target="#creditCardModal" data-backdrop="static" data-keyboard="false" data-bs-backdrop="static" data-bs-keyboard="false" style="margin-right: 1%;"><i class="material-icons text-sm">add</i>&nbsp;&nbsp;Add New Card</button></h6>
              </div>
            </div>
            <div class="card-body p-3">
              <div class="row" id="allCardDetails">
                <?php
                  foreach($cardData['data'] as $key=>$val){
                ?>
                  <div class="col-md-6 mt-2" id="<?=$val['id'];?>">
                    <div class="card card-body border card-plain border-radius-lg d-flex align-items-center flex-row">
                      <?php
                        if(isset($cardLogos[$val['brand']]))
                          echo '<img class="w-10 me-3 mb-0" src="'.$assetsPath.'/card_logos/'.$cardLogos[$val['brand']].'" alt="logo">';
                        else
                          echo '<span class="w-20 me-3 mb-0">'.$val['brand'].'</span>';
                      ?>
                      <h6 class="mb-0">****&nbsp;&nbsp;&nbsp;****&nbsp;&nbsp;&nbsp;****&nbsp;&nbsp;&nbsp;<?=$val['last4'];?></h6>
                      <i class="material-icons ms-auto text-dark cursor-pointer deleteCard" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Card" data-card-id="<?=$val['id'];?>">delete</i>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- CARDS LIST END -->
  </main>
  <?php require __DIR__.'/layouts/coreFooter.php'; ?>
  <?php require __DIR__.'/layouts/baseFooter.php'; ?>
  <script src='https://cdnjs.cloudflare.com/ajax/libs/jquery.payment/3.0.0/jquery.payment.min.js'></script>
  <script>

    var cardLogos = <?=json_encode($cardLogos)?>;

    $(function () {
      $('#card_no').payment('formatCardNumber');
      $('#cvv').payment('formatCardCVC');
    });

    //show Card no in text field
    $("#card_no").keyup(function(){
      $("#card_brand").text($.payment.cardType($(this).val()));
    });

    // DELETE CREDIT CARD FUNCTION START
    $(document).on('click', '.deleteCard', function() {
      $.LoadingOverlay("show", {image: "",text: "Deleting Card Please Wait..."});
      var cardId = $(this).data("card-id");
      $.ajax({
        type: "POST",
        url: "",
        data: {action:"deleteCard",cardId:cardId},
        success: function (data) {
          $.LoadingOverlay("hide");
          if(data.status==200){
            showNotify(data.message,"success");
            $("#"+cardId).remove();
          }
          else
            showNotify(data.message,"danger");
        },
        error: function() {
          $.LoadingOverlay("hide");
          showNotify("Some Error Occured","danger");
        }
      });
    });
    // DELETE CREDIT CARD FUNCTION END

    // ADD NEW CREDIT CARD FUNCTION START
    $("form").submit(function( event ) {
      var formData = $(this).serialize();
      event.preventDefault();
      var validCard = $.payment.validateCardNumber($('#card_no').val());
      if (!validCard) {
        showNotify("Your card is not valid!","danger");
        return false;
      }
      else{
        var validExpiry = $.payment.validateCardExpiry($('#ccmonth').val(), $('#ccyear').val());
        if (!validExpiry) {
          showNotify("Invalid Expiry Date","danger");
          return false;
        }
        else{
          var validCvv = $.payment.validateCardCVC($('#cvv').val());
          if (!validCvv) {
            showNotify("CVV is Invalid","danger");
            return false;
          }
        }
      }
      $.LoadingOverlay("show", {image: "",text: "Adding New Card Please Wait..."});
      $.ajax({
        type: "POST",
        url: "",
        data: formData+"&action=addNewCard",
        success: function (data) {        
          $.LoadingOverlay("hide");
          if(data.status==200){
            showNotify(data.message,"success");
            $("#creditCardModal").modal("hide");
            location.reload();
          }
          else
            showNotify(data.message,"danger");
        },
        error: function() {
          $.LoadingOverlay("hide");
          showNotify("Some Error Occured","danger");
        }
      });
    });
    // ADD NEW CREDIT CARD FUNCTION END
  </script>
</body>
</html>