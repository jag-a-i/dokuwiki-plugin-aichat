<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\plugin\aichat\Model\AbstractModel;
use dokuwiki\plugin\aichat\Model\Generic\ChatModel;
use dokuwiki\plugin\aichat\Model\Generic\EmbeddingModel;

/**
 * @group plugin_aichat
 * @group plugins
 */
class GenericThinkingTest extends \DokuWikiTest
{
    public function provideThinkingRequests(): array
    {
        return [
            'chat defaults to unchanged API payload' => [null, false, false],
            'chat option explicitly disabled' => [0, false, false],
            'chat thinking disabled when opted in' => [1, false, true],
            'embedding unaffected by chat option' => [1, true, false],
        ];
    }

    /** @dataProvider provideThinkingRequests */
    public function testThinkingOption(?int $setting, bool $embedding, bool $expected): void
    {
        $config = ['generic_apiurl' => 'http://127.0.0.1:1/v1'];
        if ($setting !== null) $config['generic_disable_thinking'] = $setting;
        $model = $embedding ? new EmbeddingModel('test-embedding', $config) : new ChatModel('test-chat', $config);
        $http = $this->getMockBuilder(DokuHTTPClient::class)->onlyMethods(['sendRequest'])->getMock();
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(
            function ($url, $body, $method) use ($http, $expected, $embedding) {
                $this->assertSame('POST', $method);
                $this->assertSame('http://127.0.0.1:1/v1/' . ($embedding ? 'embeddings' : 'chat/completions'), $url);
                $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if ($expected) {
                    $this->assertSame(['enable_thinking' => false], $payload['chat_template_kwargs']);
                } else {
                    $this->assertArrayNotHasKey('chat_template_kwargs', $payload);
                }
                $http->error = '';
                $http->resp_body = json_encode([
                    'choices' => [['message' => ['content' => 'Synthetic answer.']]],
                    'data' => [['embedding' => [0.25, 0.75]]],
                ]);
                return true;
            }
        );
        $property = new \ReflectionProperty(AbstractModel::class, 'http');
        $property->setAccessible(true);
        $property->setValue($model, $http);

        if ($embedding) {
            $this->assertSame([0.25, 0.75], $model->getEmbedding('Synthetic text.'));
        } else {
            $this->assertSame('Synthetic answer.', $model->getAnswer([['role' => 'user', 'content' => 'Synthetic question.']]));
        }
    }
}
