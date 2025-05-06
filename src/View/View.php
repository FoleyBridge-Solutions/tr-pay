<?php
namespace Fbs\trpay\View;
use NumberFormatter;

/**
 * View Class
 * 
 * Handles the rendering of templates and view components
 */
class View {

    /**
     * Renders a template with the provided data
     *
     * @param string $template    The name of the template to render
     * @param array  $data       Optional data to be extracted into template scope
     * @param bool   $client_page Whether to include the client navbar
     * @return void
     */
    public function render($template, $data = []) {
        if ($template === 'error') {
            $this->error([
                'title' => 'Programatic Error',
                'message' => 'An error occurred' . $data['message']
            ]);
            return;
        }
        extract($_SESSION);
        extract($data);
        $currency_format = numfmt_create('en_US', NumberFormatter::CURRENCY);
        require "/var/www/itflow-ng/src/View/header.php";
        require "/var/www/itflow-ng/src/View/navbar.php";
        require "/var/www/itflow-ng/src/View/$template.php";
        require "/var/www/itflow-ng/src/View/footer.php";
    }

    /**
     * Renders an error page with the given message
     *
     * @param array $message Array containing 'title' and 'message' keys
     * @return void
     */
    public function error($message) {
        extract($message);
        require "error.php";
    }
}
