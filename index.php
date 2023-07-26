<?php

use Abraham\TwitterOAuth\TwitterOAuth;
use CloudEvents\V1\CloudEventInterface;
use Google\Cloud\Datastore\DatastoreClient;
use Google\Cloud\PubSub\MessageBuilder;
use Google\Cloud\PubSub\PubSubClient;
use Google\CloudFunctions\FunctionsFramework;

// Register the function with Functions Framework.
// This enables omitting the `FUNCTIONS_SIGNATURE_TYPE=cloudevent` environment
// variable when deploying. The `FUNCTION_TARGET` environment variable should
// match the first parameter.
FunctionsFramework::cloudEvent('helloworldPubsub', 'helloworldPubsub');
FunctionsFramework::cloudEvent('publishTwitter', 'publishTwitter');


function helloworldPubsub(CloudEventInterface $event): void
{
    $log = fopen(getenv('LOGGER_OUTPUT') ?: 'php://stderr', 'wb');
    fwrite($log, "> Received pubsub message" . PHP_EOL);

    $projectId = getenv('GCLOUD_PROJECT');
    $datastore = new DatastoreClient([
        'projectId' => $projectId
    ]);

    $query = $datastore->query()
        ->kind('product_quotes')
        ->order('published_at', 'ASCENDING')
        ->limit(1);

    $results = $datastore->runQuery($query);

    foreach ($results as $entity) {
        $message = sprintf(
            '%s -- %s',
            $entity['text'],
            $entity['author']
        );

        fwrite($log, sprintf('> Found Message: %s%s', $message, PHP_EOL));

        $pubsub = new PubSubClient([
            'projectId' => $projectId,
        ]);

        $topic = $pubsub->topic(getenv('MESSAGES_BROADCAST_TOPIC'));
        $topic->publish((new MessageBuilder)->setData($message)->build());

        fwrite($log, sprintf('> Message published to topic %s%s', getenv('MESSAGES_BROADCAST_TOPIC'), PHP_EOL));

        $entity['published_at'] = new DateTimeImmutable();
        $datastore->update($entity);

        fwrite($log, sprintf('> Updated published date %s', PHP_EOL));
    }
}

function publishTwitter(CloudEventInterface $event): void {
    $log = fopen(getenv('LOGGER_OUTPUT') ?: 'php://stderr', 'wb');

    $cloudEventData = $event->getData();
    $pubSubData = base64_decode($cloudEventData['message']['data']);

    fwrite($log, sprintf('> Received pubsub message: %s%s', $pubSubData, PHP_EOL));

    $connection = new TwitterOAuth(
        getenv('CONSUMER_KEY'),
        getenv('CONSUMER_SECRET'),
        getenv('ACCESS_TOKEN'),
        getenv('ACCESS_TOKEN_SECRET')
    );

//    $credentials = $connection->get("account/verify_credentials");
//
//    fwrite($log,
//        sprintf(
//            '> Verified credentials: %d - %s% s',
//            $connection->getLastHttpCode(),
//            $connection->getLastApiPath(),
//            json_encode($connection->getLastBody()),
//            PHP_EOL
//        )
//    );

    $response = $connection->post("tweets", ["text" => $pubSubData], true);

    fwrite($log,
        sprintf(
            '> Tweet response: %d - %s - %s%s',
            $connection->getLastHttpCode(),
            $connection->getLastApiPath(),
            json_encode($connection->getLastBody()),
            PHP_EOL
        )
    );
}
