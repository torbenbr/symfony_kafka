<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Kafka\Transport;

use Psr\Log\LoggerInterface;
use RdKafka\Conf as KafkaConf;
use RdKafka\Producer as KafkaProducer;
use RdKafka\ProducerTopic as KafkaProducerTopic;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * @author Konstantin Scheumann <konstantin@konstantin.codes>
 */
class KafkaSender implements SenderInterface
{
    private $logger;
    private $serializer;
    private $rdKafkaFactory;
    private $conf;
    private $properties;

    /** @var KafkaProducer */
    private $producer;

    public function __construct(LoggerInterface $logger, SerializerInterface $serializer, RdKafkaFactory $rdKafkaFactory, KafkaConf $conf, array $properties)
    {
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->rdKafkaFactory = $rdKafkaFactory;
        $this->conf = $conf;
        $this->properties = $properties;
    }

    public function send(Envelope $envelope): Envelope
    {
        $producer = $this->getProducer();
        /** @var KafkaProducerTopic $topic */
        $topic = $producer->newTopic($this->properties['topic_name']);

        $payload = $this->serializer->encode($envelope);

        $topic->producev(
            \RD_KAFKA_PARTITION_UA,
            0,
            $payload['body'],
            $payload['key'] ?? null,
            $payload['headers'] ?? null,
            $payload['timestamp_ms'] ?? null
        );

        $code = \RD_KAFKA_RESP_ERR_NO_ERROR;
        for ($flushTry = 0; $flushTry <= $this->properties['flush_retries']; ++$flushTry) {
            $code = $producer->flush($this->properties['flush_timeout']);
            if (\RD_KAFKA_RESP_ERR_NO_ERROR === $code) {
                break;
            }
            $this->logger->info(sprintf('Kafka flush #%s didn\'t succeed.', $flushTry));
            sleep(1);
        }

        if (\RD_KAFKA_RESP_ERR_NO_ERROR !== $code) {
            throw new TransportException('Kafka producer response error: '.$code, $code);
        }

        return $envelope;
    }

    private function getProducer(): KafkaProducer
    {
        return $this->producer ?? $this->producer = $this->rdKafkaFactory->createProducer($this->conf);
    }
}
