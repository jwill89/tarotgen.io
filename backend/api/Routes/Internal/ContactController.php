<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ContactRepository;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

class ContactController extends AbstractController
{
    // Public submissions: max 5 per IP per hour.
    private const int SUBMIT_MAX_PER_WINDOW = 5;
    private const int SUBMIT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly ContactRepository $contacts,
    ) {
    }

    #[OAS\Operation\Post(
        path: '/contacts',
        summary: 'Submit a contact-form message (rate-limited: 5/IP/hour)',
        tags: ['Contact'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name', 'email', 'message'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email')),
                    new OAS\Property(property: 'message', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Submitted',
                content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Missing/invalid fields'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    /**
     * Public endpoint: submit a contact form message.
     * Rate-limited per IP to prevent spam.
     */
    public function submit(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $limiter = new RateLimiter('contact_submit', self::SUBMIT_MAX_PER_WINDOW, self::SUBMIT_WINDOW_SECONDS);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'You have submitted too many messages recently. Please try again later.'],
                429
            );
        }

        $params  = $this->parsedBody($request);
        $name    = trim((string)($params['name'] ?? ''));
        $email   = trim((string)($params['email'] ?? ''));
        $message = trim((string)($params['message'] ?? ''));

        if ($name === '' || $email === '' || $message === '') {
            return $response->withJson(
                ['error' => 'Name, email, and message are all required.'],
                400
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $response->withJson(['error' => 'Please provide a valid email address.'], 400);
        }

        $userId = $this->currentUserId();

        $contact = $this->contacts->create([
            'user_id' => $userId,
            'name'    => $name,
            'email'   => $email,
            'message' => $message,
        ]);

        if ($contact === null) {
            return $response->withJson(['error' => 'Failed to submit your message. Please try again.'], 500);
        }

        // Only count successful submissions against the limit.
        $limiter->hit($ip);

        return $response->withJson(['success' => true], 201);
    }

    /** The logged-in user's id, or null for a guest submission. */
    private function currentUserId(): ?int
    {
        Session::start();
        return Session::userId();
    }
}
