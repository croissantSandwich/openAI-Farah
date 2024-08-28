<?php
require __DIR__ . '/../vendor/autoload.php';


use Dotenv\Dotenv;
use Orhanerday\OpenAi\OpenAi;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

class OpenAIController
{
    private $openai;

    public function __construct()
    {
        $this->openai = new OpenAi($_ENV['OPENAI_API_KEY']);
        //$this->openai = new OpenAi(getenv('OPENAI_API_KEY'));
    }

    public function post()
    {
        // Parse the request body
        $input = $_POST;
        $errors = $this->validateInput($input);

        if (!empty($errors)) {
            $this->sendResponse(['errors' => $errors], 400);
            return;
        }

        $threadId = $input['threadId'] ?? null;
        $message = $input['message'];

        try {
            if (!$threadId) {
                $threadResponse = $this->openai->createThread();
                $threadData = json_decode($threadResponse, true);
                $threadId = $threadData['id'];
            }

            $messageData = [
                'role' => 'user',
                'content' => $message
            ];

            $messageResponse = $this->openai->createThreadMessage($threadId, $messageData);
            $createdMessage = json_decode($messageResponse, true);

            $this->sendResponse(['threadId' => $threadId, 'messageId' => $createdMessage['id']], 200);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function validateInput($input)
    {
        $errors = [];

        if (isset($input['threadId']) && !is_string($input['threadId'])) {
            $errors[] = 'threadId must be a string';
        }

        if (!isset($input['message']) || !is_string($input['message'])) {
            $errors[] = 'message is required and must be a string';
        }

        return $errors;
    }

    private function sendResponse($data, $statusCode = 200)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    private function waitForRun($threadId, $runId)
    {
        $startTime = time();
        $timeout = 60; // Timeout after 60 seconds

        while (true) {
            usleep(5000000); // 5000ms delay

            $runResponse = $this->openai->retrieveRun($threadId, $runId);
            $run = json_decode($runResponse, true);

            if (!in_array($run['status'], ['queued', 'in_progress'])) {
                if (in_array($run['status'], ['cancelled', 'cancelling', 'failed', 'expired'])) {
                    throw new \Exception($run['status']);
                }
                break;
            }

            if ((time() - $startTime) > $timeout) {
                throw new \Exception("Operation timed out.");
            }
        }

        return $run;
        // while (true) {
        //     usleep(5000000); // 5000ms delay

        //     $runResponse = $this->openai->retrieveRun($threadId, $runId);
        //     $run = json_decode($runResponse, true);

        //     if (!in_array($run['status'], ['queued', 'in_progress'])) {
        //         if (in_array($run['status'], ['cancelled', 'cancelling', 'failed', 'expired'])) {
        //             throw new \Exception($run['status']);
        //         }
        //         break;
        //     }
        // }

        // return $run;
    }

    public function runAssistant()
    {
        $input = $_POST;
        $threadId = $input['threadId'] ?? null;
        $createdMessageId = $input['messageId'] ?? null;
        $this->openai->setAssistantsBetaVersion("v2");

        if (!$threadId || !$createdMessageId) {
            $this->sendResponse(['error' => 'Missing threadId or messageId'], 400);
            return;
        }

        try {
            $assistantId = $_ENV['ASSISTANT_ID'];
            //$assistantId = getenv('ASSISTANT_ID');
            $data = ['assistant_id' => $assistantId];
            $runResponse = $this->openai->createRun($threadId, $data);
            $run = json_decode($runResponse, true);
            // $this->sendResponse($runResponse, 200);

            $this->waitForRun($threadId, $run['id']);

            $messagesResponse = $this->openai->listThreadMessages($threadId, [
                'after' => $createdMessageId,
                'order' => 'asc'
            ]);

            $responseMessages = json_decode($messagesResponse, true);
            $responseContent = [];

            foreach ($responseMessages['data'] as $message) {
                if ($message['role'] === 'assistant') {
                    $responseContent[] = $message['content'];
                }
            }

            $value = $responseContent[0][0]["text"]['value'];
            $this->sendResponse($value, 200);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }
}
