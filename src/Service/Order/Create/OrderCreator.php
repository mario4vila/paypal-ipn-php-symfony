<?php

namespace App\Service\Order\Create;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

final class OrderCreator
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function execute(float $amount, string $trasactionId): void
    {
        $order = new Order();
        $created = new \DateTimeImmutable();
        $order->setCreated($created);
        $order->setAmount($amount);
        $order->setStatus(Order::COMPLETED);
        $order->setTransactionId($trasactionId);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
