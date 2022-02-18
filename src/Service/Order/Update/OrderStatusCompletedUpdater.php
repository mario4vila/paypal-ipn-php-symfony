<?php

namespace App\Service\Order\Update;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderStatusCompletedUpdater
{
    public function __construct(private EntityManagerInterface $entityManager, private OrderRepository $orderRepository)
    {
    }

    public function execute(int $id, string $trasactionId): ?Order
    {
        $order = $this->orderRepository->findOneBy(['id' => $id, 'status' => Order::PENDING]);
        if (!$order) {
            return null;
        }

        $order->setStatus(Order::COMPLETED);
        $order->setTransactionId($trasactionId);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
