<?php


namespace Bigperson\Exchange1C\Services;


use Bigperson\Exchange1C\Config;
use Bigperson\Exchange1C\Exceptions\Exchange1CException;
use Bigperson\Exchange1C\Interfaces\DocumentInterface;
use Bigperson\Exchange1C\Interfaces\EventDispatcherInterface;
use Bigperson\Exchange1C\Interfaces\ModelBuilderInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Request;
use Zenwalker\CommerceML\CommerceML;

class OrderService
{
    /**
     * @var array Массив идентификаторов заказов, которые были добавлены в файл orders.xml для экспорта в 1С
     */
    protected $_ids;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var ModelBuilderInterface
     */
    private $modelBuilder;

    /**
     * CategoryService constructor.
     *
     * @param Request                  $request
     * @param Config                   $config
     * @param EventDispatcherInterface $dispatcher
     * @param ModelBuilderInterface    $modelBuilder
     */
    public function __construct(Request $request, Config $config, EventDispatcherInterface $dispatcher, ModelBuilderInterface $modelBuilder)
    {
        $this->request = $request;
        $this->config = $config;
        $this->dispatcher = $dispatcher;
        $this->modelBuilder = $modelBuilder;
    }

    /**
     * Базовый метод экспорта заказов
     *
     * @throws Exchange1CException
     */
    public function query(): string
    {
        $ordersFile = $this->config->getFullPath('orders.xml');
        $currentDateTime = Carbon::now();
        $commerceMLData = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
                           <КоммерческаяИнформация ВерсияСхемы=\"2.05\" ДатаФормирования=\"{$currentDateTime}\">
                           </КоммерческаяИнформация>";

        $ordersCommerceML = new \SimpleXMLElement($commerceMLData, null, false);
        $orderClass = $this->getOrderClass();

        if ($orderClass) {
            $ordersToExport = $orderClass::findDocuments1c();
            $this->packageOrdersToCommerceML($ordersCommerceML, $ordersToExport);
        }
        else {
            throw new Exchange1CException("Order class model is not implemented");
        }

        $ordersCommerceML->saveXML($ordersFile);
        return $ordersFile;
    }

    /**
     * @return DocumentInterface|null
     */
    protected function getOrderClass(): ?DocumentInterface
    {
        return $this->modelBuilder->getInterfaceClass($this->config, DocumentInterface::class);
    }

    public function packageOrdersToCommerceML(\SimpleXMLElement $ordersCommerceML, $ordersToExport)
    {
        unset($this->_ids);
        $this->_ids = [];
        foreach ($ordersToExport as $order) {
            $this->_ids[] = $order->id;
            $order->exported = '0000-01-01 00:00:00';
            $order->ones_id = $order->id + config('app.orderOneSIdForSync');

            $docElem = $ordersCommerceML->addChild('Документ');
            $docElem->addChild('Ид', "wc1c#order#{$order->ones_id}");
            $docElem->addChild('Номер', $order->ones_id);
            $docElem->addChild('Дата', optional(optional($order->created_at))->format('Y-m-d'));
            $docElem->addChild('Время', optional(optional($order->created_at))->format('H:i:s'));
            $docElem->addChild('ХозОперация', 'Заказ товара');
            $docElem->addChild('Роль', 'Продавец');
            $docElem->addChild('Валюта', 'RUB');
            $docElem->addChild('Сумма', number_format($order->sum,2, '.', ''));
            $docElem->addChild('Комментарий', $order->shippingAddress ? htmlspecialchars($order->shippingAddress->comment) : '');

            $counterparties = $docElem->addChild('Контрагенты');
            {
                if ($order->billingAddress) {
                    $payer = $counterparties->addChild('Контрагент');
                    {
                        $payer->addChild('Ид', "wc1c#user#{$order->billingAddress->id}");
                        $payer->addChild('Роль', 'Плательщик');
                        $payer->addChild('Наименование', $order->billingAddress->getTitle());
                        $payer->addChild('ПолноеНаименование', $order->billingAddress->getFullTitle());
                        $payer->addChild('Фамилия', $order->billingAddress->last_name);
                        $payer->addChild('Имя', $order->billingAddress->first_name);
                        $payer->addChild('Отчество', $order->billingAddress->patronymic);
                        $payer->addChild('Организация', $order->billingAddress->company);
                        $payer->addChild('ИНН', $order->billingAddress->inn);

                        $payerContacts = $payer->addChild('Контакты');
                        {
                            $payerPhone = $payerContacts->addChild('Контакт');
                            {
                                $payerPhone->addChild('Тип', 'Телефон');
                                $payerPhone->addChild('Значение', $order->billingAddress->phone);
                            }
                            $payerEmail = $payerContacts->addChild('Контакт');
                            {
                                $payerEmail->addChild('Тип', 'Почта');
                                $payerEmail->addChild('Значение', $order->billingAddress->email);
                            }
                        }

                        $payerAddress = $payer->addChild('АдресРегистрации');
                        {
                            $payerAddress->addChild('Представление', $order->billingAddress->addressRepresentation());
                            $payerPostcode = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerPostcode->addChild('Тип', 'Почтовый индекс');
                                $payerPostcode->addChild('Значение', $order->billingAddress->postcode);
                            }
                            $payerCountry = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerCountry->addChild('Тип', 'Страна');
                                $payerCountry->addChild('Значение', $order->billingAddress->country);
                            }
                            $payerRegion = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerRegion->addChild('Тип', 'Регион');
                                $payerRegion->addChild('Значение', $order->billingAddress->region);
                            }
                            $payerCity = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerCity->addChild('Тип', 'Город');
                                $payerCity->addChild('Значение', $order->billingAddress->city);
                            }
                            $payerStreet = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerStreet->addChild('Тип', 'Улица');
                                $payerStreet->addChild('Значение', $order->billingAddress->street);
                            }
                            $payerHouseNumber = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerHouseNumber->addChild('Тип', 'Дом');
                                $payerHouseNumber->addChild('Значение', $order->billingAddress->house_number);
                            }
                            $payerRoom = $payerAddress->addChild('АдресноеПоле');
                            {
                                $payerRoom->addChild('Тип', 'Квартира');
                                $payerRoom->addChild('Значение', $order->billingAddress->room);
                            }
                        }
                    }
                }
                if ($order->user) {
                    $recipient = $counterparties->addChild('Контрагент');
                    {
                        $recipient->addChild('Ид', "wc1c#user#{$order->user->id}");
                        $recipient->addChild('Роль', 'Получатель');
                        $recipient->addChild('Наименование', $order->user->getTitle());
                        $recipient->addChild('Фамилия', $order->user->last_name);
                        $recipient->addChild('Имя', $order->user->first_name);
                        $recipient->addChild('Отчество', $order->user->patronymic);
                    }
                }
            }

            $docItems = $docElem->addChild('Товары');
            {
                $orderItems = $order->getOffers1c();
                foreach ($orderItems as $orderItem) {
                    $docItem = $docItems->addChild('Товар');
                    $docItem->addChild('Ид', $orderItem->stock ? $orderItem->stock->ones_id : '');
                    $docItem->addChild('Наименование', $orderItem->stock ? htmlspecialchars($orderItem->stock->title) : '');
                    $baseItem = $docItem->addChild('БазоваяЕдиница', 'шт');
                    {
                        $baseItem->addAttribute('Код', '796');
                        $baseItem->addAttribute('НаименованиеПолное', 'Штука');
                        $baseItem->addAttribute('МеждународноеСокращение', 'PCE');
                    }
                    $docItem->addChild('ЦенаЗаЕдиницу', number_format($orderItem->soldprice * ((100 - $orderItem->discount)/100), 2, '.', ''));
                    $docItem->addChild('Количество', $orderItem->qty);
                    $docItem->addChild('Сумма', number_format($orderItem->soldprice * $orderItem->qty * ((100 - $orderItem->discount)/100), 2, '.', ''));
                    $docItemRequisites = $docItem->addChild('ЗначенияРеквизитов');
                    {
                        $docItemRequisite = $docItemRequisites->addChild('ЗначениеРеквизита');
                        {
                            $docItemRequisite->addChild('Наименование', 'ТипНоменклатуры');
                            $docItemRequisite->addChild('Значение', 'Товар');
                        }
                    }
                }
            }

            $docRequisites = $docElem->addChild('ЗначенияРеквизитов');
            {
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Тип заказа');
                    $orderType = ($order->type == 'Дозаказ') ? ("{$order->type} к заказу №{$order->mainorder->ones_id} от {optional($order->mainorder->created_at)->format('d.m.Y')} на " . number_format($order->mainorder->sum, 2, '.', '') . " руб.") : $order->type;
                    $docRequisite->addChild('Значение', $orderType);
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Метод оплаты');
                    $docRequisite->addChild('Значение', 'Оплата по реквизитам');
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Заказ оплачен');
                    $docRequisite->addChild('Значение', ($order->status == 'Оплачен' ||
                        $order->status == 'В доставке' ||
                        $order->status == 'Выполнен') ? 'true' : 'false');
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Доставка разрешена');
                    $docRequisite->addChild('Значение', ($order->status == 'Оплачен' ||
                        $order->status == 'В доставке' ||
                        $order->status == 'Выполнен') ? 'true' : 'false');
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Отменен');
                    $docRequisite->addChild('Значение', ($order->status == 'Отменен') ? 'true' : 'false');
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Финальный статус');
                    $docRequisite->addChild('Значение', ($order->status == 'Выполнен' ||
                        $order->status == 'Отменен' ||
                        $order->status == 'Расформирован') ? 'true' : 'false');
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Статус заказа');
                    $docRequisite->addChild('Значение', $order->status);
                }
                $docRequisite = $docRequisites->addChild('ЗначениеРеквизита');
                {
                    $docRequisite->addChild('Наименование', 'Дата изменения статуса');
                    $docRequisite->addChild('Значение', $order->updated_at->format('Y-m-d H:i:s'));
                }
            }
            $order->save();
        }

        $sessionId = $this->request->cookies->get('exchange1c_session_id', null);
        if ($sessionId == null) {
            $sessionId = session()->getId();
        }
        session()->put($sessionId, $this->_ids);
        $ordersInStr = implode(",", $this->_ids);
        \Log::channel('import_1c')->debug("ORDER 1C EXCHANGE 1. Orders ids sent to 1C: [{$ordersInStr}]");
    }

    public function setOrdersExported(): string
    {
//        $sessionId = $this->request->cookies->get('exchange1c_session_id');
//        $ids = session()->get($sessionId, []);
//        $orderInStr = implode(",", $ids);
//        \Log::debug("ORDER EXCHANGE 2. setOrdersExported.\nSessionId from request: {$sessionId}\nOrders ids in session: [{$orderInStr}]");
        $response = "failure\nOrders NOT marked as sent";
        $orderClass = $this->getOrderClass();
        if ($orderClass) {
            $ids = $orderClass::where('exported', '0000-01-01 00:00:00')->get('id');
            if ($ids) {
                $orderClass::whereIn('id', $ids)->update(['exported' => Carbon::now()]);
                $response = "success\n";
                $response .= "Orders marked as sent\n";
            }
        }
        else {
            throw new Exchange1CException("Order class model is not implemented");
        }
        return $response;
    }

    public function import(): void
    {
        $orderClass = $this->getOrderClass();
        if ($orderClass) {
            $filename = basename($this->request->get('filename'));
            $commerce = new CommerceML();
            $commerce->loadOrdersXml($this->config->getFullPath($filename));
            foreach($commerce->order->documents as $order) {
                $orderOneSId = (string)$order->Ид;
                $orderOneSNumber = (string)$order->Номер;
                // Извлечь значение реквизита "Статус заказа"
                // Извлечь значение реквизита "Дата изменения статуса"
                $orderClass::updateOrderStatus($orderOneSId, $orderOneSNumber);
                \Log::channel('import_1c')->debug("ORDER 1C EXCHANGE. Order ид={$orderOneSId}, номер={$orderOneSNumber} changed");
            }
        }
        else {
            throw new Exchange1CException("Order class model is not implemented");
        }
        


    }
}
