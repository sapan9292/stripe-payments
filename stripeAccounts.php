<?php 

$pageTitle = "Stripe Accounts";
require __DIR__.'/layouts/header.php';
include('stripe_config.php');
define('CLIENT_ID', $clientId);
define('API_KEY', $secretKey);
define('TOKEN_URI', 'https://connect.stripe.com/oauth/token');
define('AUTHORIZE_URI', 'https://connect.stripe.com/oauth/authorize');
$errorMessage = "";
$errorMessageClass = "danger";
if (isset($_GET['code'])) {
  $token_request_body = array(
    'client_secret' => API_KEY,
    'grant_type' => 'authorization_code',
    'client_id' => CLIENT_ID,
    'code' => $_GET['code'],
  );
  $req = curl_init(TOKEN_URI);
  curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($req, CURLOPT_POST, true );
  curl_setopt($req, CURLOPT_POSTFIELDS, http_build_query($token_request_body));
  $respCode = curl_getinfo($req, CURLINFO_HTTP_CODE);
  $resp = json_decode(curl_exec($req), true);
  curl_close($req);
  try {
    if(isset($resp['error']))
      $errorMessage = $resp['error_description'];
    else{
      $strp_user_id = $resp['stripe_user_id'];
      $accountData = $stripe->accounts->retrieve($strp_user_id,[]);
      $accountData = json_encode($accountData);
      $user_id = $_SESSION['user_id'];
      $query = "INSERT into str_stripeAccount(account_json,user_id_fk,access_tokens) VALUES(?,?,?)";
      $stmt = $connection->prepare($query);
      $stmt->bind_param("sis",$accountData,$user_id,json_encode($resp));
      try{
        $result = $stmt->execute();
        $errorMessage = "Account Linked Successfully";
        $errorMessageClass = "success";
      }
      catch(Exception $e){
        $errorMessage = $e->getMessage();
      }
    }
  } catch (\Throwable $th) {
    $errorMessage = $e->getMessage();
  }  
}
else if (isset($_GET['error']))
  $errorMessage = $_GET['error_description'];
$authorize_request_body = array(
  'response_type' => 'code',
  'scope' => 'read_write',
  'client_id' => CLIENT_ID
);
$oauthUrl = AUTHORIZE_URI . '?' . http_build_query($authorize_request_body);
$user_id = $_SESSION['user_id'];
try{
  $fetchStrAccsQuery = "SELECT account_json from str_stripeAccount where user_id_fk=?";
  $stmt = $connection->prepare($fetchStrAccsQuery);
  $stmt->bind_param("s",$user_id);
  $stmt->execute();
  $result = $stmt->get_result();
}
catch(Exception $e){
  die("Some Error Occured");
}

?>
<style type="text/css">
  .NewStripeAcc{
    float: right;
    margin-right: 12px;
  }
</style>
<body class="g-sidenav-show bg-gray-200">
  <?php require __DIR__.'/layouts/sidenav1.php'; ?>
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg ">
    <?php 
    require __DIR__.'/layouts/topbar.php';
    if($errorMessage!="")
      echo '<div class="alert alert-'.$errorMessageClass.' text-white " role="alert">'.$errorMessage.'</div>';
    ?>
    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card my-4">
              <div class="card-header p-0 position-relative mt-n4 mx-3 z-index-2">
              <div class="bg-gradient-info shadow-info border-radius-lg pt-4 pb-3">
                <h6 class="text-white text-capitalize ps-3" style="text-align-last: justify;"><span style="display: -webkit-inline-box;">Stripe Accounts</span><a class="btn bg-gradient-dark NewStripeAcc" href="<?=$oauthUrl?>" <?php if($_GET['page'] == 'erp')echo "target='_blank'";?> style="margin-right: 1%;"><i class="material-icons text-sm">add</i>&nbsp;&nbsp;Link New Account</a></h6>
              </div>
            </div>
            <div class="card-body px-0 pb-2">
              <div class="table-responsive p-0">
                <table class="table align-items-center mb-0" style="overflow: hidden;">
                  <thead>
                    <tr>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Id</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Name</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Country</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Account Status</th>
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Type</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      while($row = mysqli_fetch_assoc($result)) {
                        $strAccountData = json_decode($row['account_json'],true);
                    ?>
                      <tr>
                        <td>
                          <div class="d-flex px-2 py-1">
                            <div class="d-flex flex-column justify-content-center">
                              <h6 class="mb-0 text-sm"><?=$strAccountData['id']?></h6>
                            </div>
                          </div>
                        </td>
                        <td>
                          <p class="text-xs font-weight-bold mb-0"><?=$strAccountData['business_profile']['name']?></p>
                        </td>
                        <td>
                          <p class="text-xs font-weight-bold mb-0"><?=$strAccountData['country']?></p>
                        </td>
                        <td>
                          <p class="text-xs font-weight-bold mb-0"><?=($strAccountData['charges_enabled']) ? "Active" : "Verification Pending";?></p>
                        </td>
                        <td class="align-middle text-center text-sm">
                          <p class="text-xs font-weight-bold mb-0"><?=$strAccountData['type']?></p>
                        </td>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?php 
  require __DIR__.'/layouts/coreFooter.php';
  require __DIR__.'/layouts/baseFooter.php';
  ?>
</body>
</html>