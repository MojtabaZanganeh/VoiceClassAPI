<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use DateTime;
use DateTimeZone;
use Exception;
use Sendpulse\RestApi\ApiClient;
use Sendpulse\RestApi\ApiClientException;
use Sendpulse\RestApi\Storage\FileStorage;

class Emails extends Support
{
    use Base, Sanitizer;

    private static string $clientId;
    private static string $clientSecret;

    public function __construct()
    {
        parent::__construct();
        self::$clientId = $_ENV['SENDPULSE_CLIENT_ID'] ?? '';
        self::$clientSecret = $_ENV['SENDPULSE_CLIENT_SECRET'] ?? '';
    }

    public function send_email_manually($params)
    {
        $this->check_role(['admin']);
        $this->check_params($params, ['recipient_name', 'recipient_email', 'subject', 'content']);

        $attachments = $_FILES['attachments'] ?? [];

        $result = $this->send_email(
            $params['recipient_email'],
            $params['recipient_name'],
            $params['subject'],
            null,
            $params['content'],
            $attachments,
            null,
            [],
            true
        );

        if (!$result) {
            Response::error('ایمیل ارسال نشد');
        }
        Response::success('ایمیل ارسال شد');
    }

    public function get_sent_emails($params)
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = (int) ($params['limit'] ?? 20);
        $limit = min($limit, 20);
        $offset = ($page - 1) * $limit;

        if (self::$clientId === '' || self::$clientSecret === '') {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new ApiClient(self::$clientId, self::$clientSecret, new FileStorage());

            $filters = ['limit' => $limit, 'offset' => $offset];
            foreach (['from', 'to', 'sender', 'recipient', 'country'] as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $emails = $apiClient->get('smtp/emails', $filters) ?: [];

            foreach ($emails as &$email) {
                $dateObj = new DateTime($email['send_date'], new DateTimeZone('UTC'));
                $dateObj->setTimezone(new DateTimeZone('Asia/Tehran'));
                $email['send_date'] = $dateObj->format('Y-m-d H:i:s');
            }

            $total = $this->get_total_sent_emails($filters);
            $totalPages = max(1, (int) ceil($total / $limit));

            Response::success('لیست ایمیل های ارسالی دریافت شد', 'emailsData', [
                'emails' => $emails,
                'total' => $total,
                'total_pages' => $totalPages
            ]);

        } catch (ApiClientException $e) {
            error_log('SendPulse API Error (getSentEmails): ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw new Exception('خطا در دریافت لیست ایمیل‌ها: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('getSentEmails Error: ' . $e->getMessage());
            Response::error('خطا در دریافت لیست ایمیل های ارسالی');
        }
    }

    public function get_total_sent_emails($params): int
    {
        if (self::$clientId === '' || self::$clientSecret === '') {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new ApiClient(self::$clientId, self::$clientSecret, new FileStorage());

            $filters = [];
            foreach (['from', 'to', 'sender', 'recipient', 'country'] as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $response = $apiClient->get('smtp/emails/total', $filters);
            if (isset($response['total']) && is_numeric($response['total'])) {
                return (int) $response['total'];
            }
            throw new Exception('پاسخ نامعتبر از API دریافت شد');

        } catch (ApiClientException $e) {
            error_log('SendPulse API Error (getTotalSentEmails): ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw new Exception('خطا در دریافت تعداد کل ایمیل‌ها: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('getTotalSentEmails Error: ' . $e->getMessage());
            throw $e;
        }
    }
}