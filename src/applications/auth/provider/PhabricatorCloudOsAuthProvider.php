<?php

final class PhabricatorCloudOsAuthProvider
    extends PhabricatorAuthProvider {

    private $adapter;

    public function getProviderName() {
        return "CloudOs";
    }

    public function getDescriptionForCreate() {
        return pht(
            'Allow users to login with their CloudOs username and password.');
    }

    public function buildLinkForm(
        PhabricatorAuthLinkController $controller) {
        throw new Exception("CloudOs provider can't be linked.");
    }

    public function getAdapter() {
        if (!$this->adapter) {
            $adapter = new PhutilEmptyAuthAdapter();
            $adapter->setAdapterType('cloudos');
            $adapter->setAdapterDomain('self');
            $this->adapter = $adapter;
        }
        return $this->adapter;
    }

    protected function renderLoginForm(
        AphrontRequest $request,
        $require_captcha = false,
        $captcha_valid = false) {

        $viewer = $request->getUser();

        $dialog = id(new AphrontDialogView())
            ->setSubmitURI($this->getLoginURI())
            ->setUser($viewer)
            ->setTitle(pht('Login to Phabricator'))
            ->addSubmitButton(pht('Login'));

//        if ($this->shouldAllowRegistration()) {
//            $dialog->addCancelButton(
//                '/auth/register/',
//                pht('Register New Account'));
//        }
//
//        $dialog->addFooter(
//            phutil_tag(
//                'a',
//                array(
//                    'href' => '/login/email/',
//                ),
//                pht('Forgot your password?')));

        $v_user = nonempty(
            $request->getStr('cloudos_username'),
            $request->getCookie(PhabricatorCookies::COOKIE_USERNAME));

        $e_user = null;
        $e_pass = null;
        $e_captcha = null;

        $errors = array();
//        if ($require_captcha && !$captcha_valid) {
//            if (AphrontFormRecaptchaControl::hasCaptchaResponse($request)) {
//                $e_captcha = pht('Invalid');
//                $errors[] = pht('CAPTCHA was not entered correctly.');
//            } else {
//                $e_captcha = pht('Required');
//                $errors[] = pht('Too many login failures recently. You must '.
//                    'submit a CAPTCHA with your login request.');
//            }
//        } else
        if ($request->isHTTPPost()) {
            // NOTE: This is intentionally vague so as not to disclose whether a
            // given username or email is registered.
            $e_user = pht('Invalid');
            $e_pass = pht('Invalid');
            $errors[] = pht('Username or password are incorrect.');
        }

        if ($errors) {
            $errors = id(new AphrontErrorView())->setErrors($errors);
        }

        $form = id(new PHUIFormLayoutView())
            ->setFullWidth(true)
            ->appendChild($errors)
            ->appendChild(
                id(new AphrontFormTextControl())
                    ->setLabel('Username')
                    ->setName('cloudos_username')
                    ->setValue($v_user)
                    ->setError($e_user))
            ->appendChild(
                id(new AphrontFormPasswordControl())
                    ->setLabel('Password')
                    ->setName('cloudos_password')
                    ->setError($e_pass));

//        if ($require_captcha) {
//            $form->appendChild(
//                id(new AphrontFormRecaptchaControl())
//                    ->setError($e_captcha));
//        }

        $dialog->appendChild($form);

        return $dialog;
    }

    public function processLoginRequest(
        PhabricatorAuthLoginController $controller) {

        $request = $controller->getRequest();
        $viewer = $request->getUser();
        $response = null;
        $account = null;

        $username = $request->getStr('cloudos_username');
        $password = $request->getStr('cloudos_password');

        $has_password = strlen($password);

        if (!strlen($username) || !$has_password) {
            $response = $controller->buildProviderPageResponse(
                $this,
                $this->renderLoginForm($request, 'login'));
            return array($account, $response);
        }

        if ($request->isFormPost()) {
            try {
                if (strlen($username) && $has_password) {
                    $account_id = $this->authenticate($username, $password);
                    if ($account_id != null) {
                        return array($this->loadOrCreateAccount($account_id), $response);;
                    }
                }
                throw new Exception('Username or password was incorrect');
            } catch (Exception $ex) {
                error_log("authenticate: threw exception: " . var_export($ex, true));
                $response = $controller->buildProviderPageResponse(
                    $this,
                    $this->renderLoginForm($request, 'login'));
                return array($account, $response);
            }
        }
    }

    function handleResponse ($response) { //, $successmessage) {
        $code = $response->code;
        if ($code == 200 || $code == 201 || $code == 204) {
//            $_SESSION['message'] = $successmessage;
            return $response;
//
//        } elseif ($code == 422) {
//            setValidationErrorsForSession($response->body);
//            redirect($_POST['errorPage']);
//
//        } elseif ($code == 404) {
//            $message = is_null($response->body) ? "" : is_null($response->body->resource) ? $response->body : $response->body->resource;
//            $err = array('messageTemplate' => 'NOT_FOUND', 'message' => $message, 'invalidValue' => '');
//            setValidationErrorsForSession(array((object) $err));
//            redirect($_POST['errorPage']);
//
//        } elseif ($code == 403) {
//            $err = array('messageTemplate' => 'FORBIDDEN', 'message' => $response->body, 'invalidValue' => '');
//            setValidationErrorsForSession(array((object) $err));
//            redirect($_POST['errorPage']);
//
        } else {
//            $err = array('messageTemplate' => 'UNKNOWN RESPONSE', 'message' => 'server response (' . $code . ') body=' . $response->body . "\n", 'invalidValue' => '');
            return null;
            // echo 'server response (' . $code . ') body=' . $response->body . "\n";
        }
    }

    private function authenticate($username, $password) {
        $root = dirname(phutil_get_library_root('phabricator'));
        require_once $root.'/externals/httpful/bootstrap.php';

        $CLOUDOS_API = "http://127.0.0.1:3001"; // todo: make this configurable/discoverable
        $request = array("name" => $username, "password" => $password);
        $response = $this->handleResponse(\Httpful\Request::post($CLOUDOS_API . "/api/accounts", json_encode($request), "application/json")->send());

        if ($response != null) {
            return $username . "/cloudos";
        } else {
            return null;
        }
    }
}
?>