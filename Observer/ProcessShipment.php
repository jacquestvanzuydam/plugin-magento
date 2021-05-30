<?php

namespace Pargo\CustomShipping\Observer;

use Magento\Framework\Event\ObserverInterface;
use Pargo\CustomShipping\Helper\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order;
use \Psr\Log\LoggerInterface;

class ProcessShipment implements ObserverInterface
{
    private $logger;
    protected $helper;
    protected $curl;
    protected $track;

    public function __construct(
        Config $helper,
        Curl $curl,
        TrackFactory $track, 
        LoggerInterface$logger
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->track = $track;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->logger->info('Pargo: Execute Shipment');

        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();
        if ($order->getShippingMethod() !== 'pargo_customshipping_pargo_customshipping') {
            $this->logger->info('Pargo: Shipping method mismatch');
            $this->logger->info('Pargo: Shipping method set is ' . $order->getShippingMethod());
            return;
        }

        $this->logger->info('Pargo: Shipping method matched successfully');

        $shippingAddress = $invoice->getShippingAddress()->getData();
        $billingAddress = $invoice->getBillingAddress()->getData();
        // Fix start
        // we need to account for multiple dashes in the address and take the last item in array as this is the pup code
        // the fact that some pups have dashes in their names has brought out this code limitation.
        // remove
        // $pickUpPointCode = explode('-', $shippingAddress['company'])[1];
        // add
        $addressDetails = explode('-', $shippingAddress['company']);

        $this->logger->info('Pargo: Shipping Address Details' . $shippingAddress['company']);

        $size = sizeof($addressDetails);
        $pickUpPointCode = $addressDetails[$size-1];
        $this->logger->info('Pargo: Pickup Point Reference: ' . $pickUpPointCode);
        // Fix end
        
        $this->logger->info('Pargo: Submit Shipping');

        $this->submitShipment($order, $billingAddress, $pickUpPointCode);
    }

    /**
     * @param Order $order
     * @param array $billingAddress
     * @param string $pickUpPointCode
     */
    private function submitShipment($order, $billingAddress, $pickUpPointCode)
    {
        $this->logger->info('Pargo: Submit Shipment');

        $token = $this->authenticate();
        if (!$token) {

            $this->logger->error('Pargo: API Authentication Failed.');

            $order->addStatusHistoryComment("Pargo authentication failed");
            $order->save();

            return;
        }

        $data = [
            'data' => [
                'type' => 'W2P',
                'attributes' => [
                    'warehouseAddressCode' => '',
                    'returnAddressCode' => '',
                    'trackingCode' => '',
                    'externalReference' => $order->getIncrementId(),
                    'pickupPointCode' => $pickUpPointCode,
                    'courierCode' => '',
                    'consignee' => [
                        'firstName' => $billingAddress['firstname'],
                        'lastName' => $billingAddress['lastname'],
                        'email' => $billingAddress['email'],
                        'phoneNumbers' => [
                            $billingAddress['telephone']
                        ]
                    ]
                ]
            ]
        ];
        $url = $this->helper->getUrl();
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url . '/orders',
            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            $this->logger->error('Pargo: Order API request failed.');
            $order->addStatusHistoryComment("Pargo consignment export failed");
            $order->save();

            return;
        } else {
            $response = json_decode($response);
            $data = [
                'carrier_code' => 'custom',
                'title' => 'Pargo Tracking Code',
                'number' => $response->data->attributes->orderData->trackingCode, // Replace with your tracking number
            ];

            $this->logger->info('Pargo: Order tracking code: ' . $response->data->attributes->orderData->trackingCode);

            $track = $this->track->create()->addData($data);
            $message = "Success! Created waybill <a href='" . $response->data->attributes->orderData->orderLabel . "' target='_blank'>" . $response->data->attributes->orderData->trackingCode . "</a>";
            $order->addStatusHistoryComment($message);
            $order->save();
        }
    }

    /**
     * @return bool
     */
    private function authenticate()
    {
        $this->logger->info('Pargo: Authenticating API');

        $url = $this->helper->getUrl();
        $username = $this->helper->getUsername();
        $password = $this->helper->getPassword();
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url . '/auth',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode(array('username' => $username, 'password' => $password)),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            $this->logger->error('Pargo: Failed to authenticate API');
            return false;
        } else {
            $this->logger->info('Pargo: API Authentication successful');
            $response = json_decode($response);

            return $response->access_token;
        }
    }
}


