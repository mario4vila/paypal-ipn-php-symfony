<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\Order\Create\OrderCreator;
use App\Service\Order\Update\OrderStatusCompletedUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OrderController extends AbstractController
{
    public function __construct(private OrderRepository $orderRepository)
    {
    }

    #[Route('', name: 'orders', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->get('status', '');

        $orders = $this->orderRepository->findAll();
        return $this->render('order/index.html.twig', [
            'status' => $status,
            'orders' => $orders,
        ]);
    }

    #[Route('/new_order', name: 'order_create', methods: ['GET'])]
    public function create($message = '', $alert = ''): Response
    {
        return $this->render('order/create.html.twig', [
            'message'           => $message,
            'alert'             => $alert,
        ]);
    }

    #[Route('/create_order', name: 'create_order', methods: ['POST'])]
    public function createOrder(Request $request, OrderCreator $orderCreator): Response
    {
        $item_name  = $request->get('item_name');
        $amount     = $request->get('amount');
        $quantity   = $request->get('quantity');

        # TO DO: Aquí podriamos validar y verificar producto, stock... sin tan solo tuviera productos 😭

        $txn_id = '';
        $mc_gross = $quantity * $amount;
        $order = $orderCreator->execute($mc_gross, $txn_id);

        $paypalBusiness = $this->getParameter('paypal.business');
        $paypalHost     = $this->getParameter('paypal.host');

        return $this->render('order/paypal_form.html.twig', [
            'item_name'         => $item_name,
            'amount'            => $amount,
            'quantity'          => $quantity,
            'custom'            => $order->getId(),
            'paypalBusiness'    => $paypalBusiness,
            'paypalHost'        => $paypalHost,
        ]);
    }

    #[Route('/process_order_paypal', name: 'process_order_paypal', methods: ['GET'])]
    public function pdtPaypal(Request $request): Response
    {
        // Para cambiar al entorno de producción cambiar a: www.paypal.com en el archivo .env
        $paypalHostname = $this->getParameter('paypal.host');

        // El token lo obtenemos en las opciones de nuestra cuenta Paypal cuando activamos PDT
        $pdtIdentityToken = $this->getParameter('paypal.token');

        $tx = $request->get('tx', '');

        $req = "cmd=_notify-synch&tx={$tx}&at={$pdtIdentityToken}";

        $ch = curl_init("https://{$paypalHostname}/cgi-bin/webscr");
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_SSLVERSION, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . "/Cert/cacert.pem");
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: PHP-IPN-Verification-Script',
            'Connection: Close',
        ));

        $response = curl_exec($ch);
        if (!($response)) {
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL error: [$errno] $errstr");
        }

        $info = curl_getinfo($ch);
        $httpCode = $info['http_code'];
        if ($httpCode != 200) {
            throw new \Exception("PayPal responded with http code $httpCode");
        }

        curl_close($ch);

        // Dividimos $response por líneas
        $lines = explode("\n", trim($response));
        $keyarray = array();

        // Validamos la respuesta
        if (strcmp($lines[0], "FAIL") == 0 || strcmp($lines[0], "SUCCESS") != 0) {
            // Podriamos registrar datos para realizar una investigación
            $msg = "FAIL";
            return $this->create($msg, 'danger');
        }

        for ($i = 1; $i < count($lines); $i++) {
            $temp = explode("=", $lines[$i], 2);
            $keyarray[urldecode($temp[0])] = urldecode($temp[1]);
        }

        // En el siguiente enlace puedes encontrar una lista completa de Variables IPN y PDT.
        // https://developer.paypal.com/docs/api-basics/notifications/ipn/IPNandPDTVariables/
        $mc_gross       = $keyarray['mc_gross'];
        $mc_currency    = $keyarray['mc_currency'];
        $payment_status = $keyarray['payment_status'];
        $quantity       = $keyarray['quantity'];
        $item_name      = $keyarray['item_name'];
        $txn_id         = $keyarray['txn_id'];
        $custom         = (int)$keyarray['custom'];

        // Verificamos que el estado de pago esté Completado
        if ($payment_status != "Completed") {
            return $this->create('El pago no ha sido completado.', 'danger');
        }

        $msg = "<h1>¡Hemos procesado tu pago exitosamente!</h1> 
            Recibimos {$mc_gross} Euros en concepto de: {$quantity} {$item_name}.<hr>
            Vuelve a comprar!";
        return $this->create($msg, 'success');
    }

    #[Route('/paypal_listener', name: 'paypal_listener', methods: ['POST'])]
    public function listenerPaypal(Request $request, OrderStatusCompletedUpdater $orderStatusCompletedUpdater): Response
    {
        // Para cambiar al entorno de producción cambiar a: www.paypal.com en el archivo .env
        $paypalHostname = $this->getParameter('paypal.host');

        $raw_post_data = file_get_contents('php://input');

        $raw_post_array = explode('&', $raw_post_data);
        $myPost = array();

        foreach ($raw_post_array as $keyval) {
            $keyval = explode("=", $keyval);
            if (count($keyval) == 2) {
                $myPost[$keyval[0]] = urldecode($keyval[1]);
            }
        }

        $req = 'cmd=_notify-validate';

        $get_magic_quotes_exists = false;
        if (function_exists('get_magic_quotes_gpc')) {
            $get_magic_quotes_exists = true;
        }

        foreach ($myPost as $key => $value) {
            if ($get_magic_quotes_exists == true) {
                $value = urlencode(stripslashes($value));
            } else {
                $value = urlencode($value);
            }
            $req .= "&$key=$value";
        }

        $ch = curl_init("https://{$paypalHostname}/cgi-bin/webscr");
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_SSLVERSION, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . "/Cert/cacert.pem");
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: PHP-IPN-Verification-Script',
            'Connection: Close',
        ));

        $response = curl_exec($ch);
        if (!($response)) {
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL error: [$errno] $errstr");
        }

        $info = curl_getinfo($ch);
        $httpCode = $info['http_code'];
        if ($httpCode != 200) {
            throw new \Exception("PayPal responded with http code $httpCode");
        }

        curl_close($ch);

        if (strcmp($response, "INVALID") == 0) {
            return new Response('INVALID');
        }

        if (strcmp($response, "VERIFIED") != 0) {
            return new Response('NOT VERIFIED');
        }

        $payment_status    = $request->get('payment_status', '');
        $txn_type          = $request->get('txn_type', '');
        $txn_id            = $request->get('txn_id', '');
        $mc_gross          = $request->get('mc_gross', 0.00);
        $quantity          = $request->get('quantity', 0);
        $item_name         = $request->get('item_name', '');
        $custom            = (int)$request->get('custom', '');

        if ($txn_type == "subscr_signup") {
        } elseif ($txn_type == "subscr_payment" && $payment_status == "Completed") {
        } elseif ($txn_type == "subscr_cancel") {
        } elseif ($txn_type == 'web_accept' && $payment_status == 'Completed') {
            $order = $this->orderRepository->findOneBy(['transactionId' => $txn_id]);
            if ($order) {
                return new Response("Este pedido con el identificador [{$txn_id}] ya ha sido procesado.");
            }

            $order = $orderStatusCompletedUpdater->execute($custom, $txn_id);
            if (!$order) {
                return new Response("No se encuentra el registro del pedido con el id [{$custom}] o no está en estado pendiente.");
            }

            return new Response("El pedido fue procesado exitosamente. \n");
        }

        return new Response('success');
    }
}
