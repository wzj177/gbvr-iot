<?php

namespace CoreW\Business\Product\Event;

use CoreW\Business\Common\EventSubscriber;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Event\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductEventSubscriber extends EventSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            'vr.product.view' => 'onVRView'
        ];
    }

    public function onVRView(Event $event)
    {
       $product = $event->getSubject();
       if (empty($product)) {
           return false;
       }

       return $this->getProductService()->increaseViewCount($product['id']);
    }

    /**
     * @return ProductService
     */
    protected function getProductService(): ProductService
    {
        return $this->getBiz()->service('Product:ProductService');
    }
}