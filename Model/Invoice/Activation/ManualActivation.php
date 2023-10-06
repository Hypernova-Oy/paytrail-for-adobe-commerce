<?php

namespace Paytrail\PaymentService\Model\Invoice\Activation;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\InvoiceOrder;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\Order\OrderStateResolverInterface;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Paytrail\PaymentService\Helper\ApiData;
use Paytrail\PaymentService\Model\ReceiptDataProvider;

class ManualActivation
{
    /**
     * @var TransactionCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var ApiData
     */
    private $apiData;

    /**
     * @var InvoiceOrder
     */
    private $invoiceOrder;
    private $orderStateResolver;
    private OrderRepositoryInterface $orderRepository;
    private Config $config;

    /**
     * ManualActivation constructor.
     *
     * @param TransactionCollectionFactory $collectionFactory
     * @param ApiData $apiData
     * @param InvoiceOrder $invoiceOrder
     */
    public function __construct(
        TransactionCollectionFactory      $collectionFactory,
        ApiData                           $apiData,
        InvoiceOrder                      $invoiceOrder,
        OrderStateResolverInterface       $orderStateResolver,
        OrderRepositoryInterface          $orderRepository,
        Config $config
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->apiData = $apiData;
        $this->invoiceOrder = $invoiceOrder;
        $this->orderStateResolver = $orderStateResolver;
        $this->orderRepository = $orderRepository;
        $this->config = $config;
    }

    /**
     * Activate invoice.
     *
     * @param int $orderId
     * @return void
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function activateInvoice(int $orderId)
    {
        /** @var \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection $transactions */
        $transactions = $this->collectionFactory->create();
        $transactions->addOrderIdFilter($orderId);

        /** @var \Magento\Sales\Api\Data\TransactionInterface $transaction */
        foreach ($transactions->getItems() as $transaction) {
            $info = $transaction->getAdditionalInformation();

            /*
             * Read only previous api status to indicate if order needs to activate. Reading only config here can
             * Leave some orders stuck in pending state if admin disables delayed invoice activation when some orders
             * are pending activation.
            */
            if (isset($info['raw_details_info']['method'])
                && in_array(
                    $info['raw_details_info']['method'],
                    Flag::SUB_METHODS_WITH_MANUAL_ACTIVATION_SUPPORT
                ) && $info['raw_details_info']['api_status'] === ReceiptDataProvider::PAYTRAIL_API_PAYMENT_STATUS_PENDING
            ) {
                $this->sendActivation($transaction->getTxnId(), $orderId);
            }
        }
    }

    /**
     * Send invoice activation to Paytrail while submit shipment.
     *
     * @param string $txnId
     * @param int $orderId
     * @return void
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function sendActivation($txnId, $orderId)
    {
        // Activation returns a status "OK" if the payment was completed upon activation but the return has no signature
        // Without signature Hmac validation embedded in payment processing cannot be passed. This can be resolved with
        // Recurring payment HMAC updates.
        // TODO Use recurring payment HMAC processing here to mark order as paid if response status is "OK"
        $order = $this->orderRepository->get($orderId);
        if (!$order->hasInvoices()) {
            $response = $this->apiData->processApiRequest(
                'invoice_activation',
                null,
                null,
                $txnId
            );

            if ($response['data']->getStatus() === 'ok') {
                $invoiceResult = $this->invoiceOrder->execute($orderId, true);
                if ($invoiceResult) {
                    $order->setState(
                        $this->orderStateResolver->getStateForOrder($order, [OrderStateResolverInterface::IN_PROGRESS])
                    );
                    $order->setStatus($this->config->getStateDefaultStatus($order->getState()));
                    $this->orderRepository->save($order);
                }
            }
        }
    }
}
