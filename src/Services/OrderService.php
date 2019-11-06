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
        foreach ($ordersToExport as $order) {
            $this->_ids[] = $order->id;
            $docElem = $ordersCommerceML->addChild('Документ');
            $docElem->addChild('Ид', "wc1c#order#{$order->ones_id}");
            $docElem->addChild('Номер', $order->ones_id);
            $docElem->addChild('Дата', $order->created_at->format('Y-m-d'));
            $docElem->addChild('Время', $order->created_at->format('h:m:s'));
            $docElem->addChild('ХозОперация', 'Заказ товара');
            $docElem->addChild('Роль', 'Продавец');
            $docElem->addChild('Валюта', 'RUB');
            $docElem->addChild('Сумма', $order->sum);
            $docElem->addChild('Комментарий', $order->shippingAddress ? $order->shippingAddress->comment : '');

            $counterparties = $docElem->addChild('Контрагенты');
            {
                if ($order->billingAddress) {
                    $payer = $counterparties->addChild('Контрагент');
                    {
                        $payer->addChild('Ид', "wc1c#user#{$order->billingAddress->ones_id}");
                        $payer->addChild('Роль', 'Плательщик');
                        $payer->addChild('Наименование', $order->billingAddress->getTitle());
                        $payer->addChild('ПолноеНаименование', $order->billingAddress->getFullTitle());
                        $payer->addChild('Фамилия', $order->billingAddress->last_name);
                        $payer->addChild('Имя', $order->billingAddress->first_name);
                        $payer->addChild('Отчество', $order->billingAddress->patronymic);

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
                if ($order->shippingAddress) {
                    $recipient = $counterparties->addChild('Контрагент');
                    {
                        $recipient->addChild('Ид', "wc1c#user#{$order->shippingAddress->ones_id}");
                        $recipient->addChild('Роль', 'Получатель');
                        $recipient->addChild('Наименование', $order->shippingAddress->getTitle());
                        $recipient->addChild('ПолноеНаименование', $order->shippingAddress->getFullTitle());
                        $recipient->addChild('Фамилия', $order->shippingAddress->last_name);
                        $recipient->addChild('Имя', $order->shippingAddress->first_name);
                        $recipient->addChild('Отчество', $order->shippingAddress->patronymic);

                        $recipientIdCard = $recipient->addChild('УдостоверениеЛичности');
                        {
                            $recipientIdCard->addChild('ВидДокумента', 'Паспорт');
                            $recipientIdCard->addChild('Серия', $order->shippingAddress->id_series);
                            $recipientIdCard->addChild('Номер', $order->shippingAddress->id_number);
                        }

                        $recipientContacts = $recipient->addChild('Контакты');
                        {
                            $recipientPhone = $recipientContacts->addChild('Контакт');
                            {
                                $recipientPhone->addChild('Тип', 'Телефон');
                                $recipientPhone->addChild('Значение', $order->shippingAddress->phone);
                            }
                        }

                        $recipientAddress = $recipient->addChild('АдресРегистрации');
                        {
                            $recipientAddress->addChild('Представление', $order->shippingAddress->addressRepresentation());
                            $recipientPostcode = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientPostcode->addChild('Тип', 'Почтовый индекс');
                                $recipientPostcode->addChild('Значение', $order->shippingAddress->postcode);
                            }
                            $recipientCountry = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientCountry->addChild('Тип', 'Страна');
                                $recipientCountry->addChild('Значение', $order->shippingAddress->country);
                            }
                            $recipientRegion = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientRegion->addChild('Тип', 'Регион');
                                $recipientRegion->addChild('Значение', $order->shippingAddress->region);
                            }
                            $recipientCity = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientCity->addChild('Тип', 'Город');
                                $recipientCity->addChild('Значение', $order->shippingAddress->city);
                            }
                            $recipientStreet = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientStreet->addChild('Тип', 'Улица');
                                $recipientStreet->addChild('Значение', $order->shippingAddress->street);
                            }
                            $recipientHouseNumber = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientHouseNumber->addChild('Тип', 'Дом');
                                $recipientHouseNumber->addChild('Значение', $order->shippingAddress->house_number);
                            }
                            $recipientRoom = $recipientAddress->addChild('АдресноеПоле');
                            {
                                $recipientRoom->addChild('Тип', 'Квартира');
                                $recipientRoom->addChild('Значение', $order->shippingAddress->room);
                            }
                            $recipientAddress->addChild('Комментарий', $order->shippingAddress->tc_terminal);
                        }
                    }
                }
            }

            $docItems = $docElem->addChild('Товары');
            {
                $orderItems = $order->getOffers1c();
                foreach ($orderItems as $orderItem) {
                    $docItem = $docItems->addChild('Товар');
                    $docItem->addChild('Наименование', $orderItem->solditem);
                    $baseItem = $docItem->addChild('БазоваяЕдиница', 'шт');
                    {
                        $baseItem->addAttribute('Код', '796');
                        $baseItem->addAttribute('НаименованиеПолное', 'Штука');
                        $baseItem->addAttribute('МеждународноеСокращение', 'PCE');
                    }
                    $docItem->addChild('ЦенаЗаЕдиницу', $orderItem->soldprice);
                    $docItem->addChild('Количество', $orderItem->qty);
                    $docItem->addChild('Сумма', $orderItem->soldprice * $orderItem->qty);
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
                    $docRequisite->addChild('Значение', $order->updated_at->format('Y-m-d h:m:s'));
                }
            }
        }
    }

    public function setOrdersExported(): bool
    {
        $orderClass = $this->getOrderClass();
        if ($orderClass) {
            $orderClass::whereIn('id', $this->_ids)->update(['exported' => Carbon::now()]);
            return true;
        }
        else {
            throw new Exchange1CException("Order class model is not implemented");
        }
    }
}