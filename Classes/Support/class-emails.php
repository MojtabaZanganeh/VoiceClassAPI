<?php
namespace Classes\Support;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Users\Users;
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

    public function send_email_manually($params)
    {
        $this->check_role(['admin']);

        $this->check_params($params, ['recipient_name', 'recipient_email', 'subject', 'content']);

        $result = $this->send_email($params['recipient_email'], $params['recipient_name'], $params['subject'], null, $params['content']);

        if (!$result) {
            Response::error('ایمیل ارسال نشد');
        }

        Response::success('ایمیل ارسال شد');
    }

    public function get_sent_emails($params)
    {
        $page = $params['page'] ?? 1;
        $limit = !empty($params['limit']) && $params['limit'] <= 20 ? $params['limit'] : 20;

        $offset = ($page - 1) * $limit;

        if ($limit > 100) {
            $limit = 100;
        }

        $clientId = $_ENV['SENDPULSE_CLIENT_ID'];
        $clientSecret = $_ENV['SENDPULSE_CLIENT_SECRET'];

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new ApiClient(
                $clientId,
                $clientSecret,
                new FileStorage()
            );

            $filters = [
                'limit' => $limit,
                'offset' => $offset
            ];
            $allowedKeys = ['from', 'to', 'sender', 'recipient', 'country'];
            foreach ($allowedKeys as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $emails = $apiClient->get('smtp/emails', $filters);

            if (!$emails) {
                Response::success('ایمیلی یافت نشد', [
                    'emails' => [],
                    'total' => 0,
                    'total_pages' => 1
                ]);
            }

            foreach ($emails as &$email) {
                $date_obj = new DateTime($email['send_date'], new DateTimeZone('UTC'));
                $date_obj->setTimezone(new DateTimeZone('Asia/Tehran'));
                $email['send_date'] = $date_obj->format('Y-m-d H:i:s');
            }

            $total = $this->get_total_sent_emails($filters);

            $totalPages = ceil($total / $limit);

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
        $clientId = $_ENV['SENDPULSE_CLIENT_ID'];
        $clientSecret = $_ENV['SENDPULSE_CLIENT_SECRET'];

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('خطا در دریافت اطلاعات تنظیمات');
        }

        try {
            $apiClient = new \Sendpulse\RestApi\ApiClient(
                $clientId,
                $clientSecret,
                new \Sendpulse\RestApi\Storage\FileStorage()
            );

            $filters = [];
            $allowedKeys = ['from', 'to', 'sender', 'recipient', 'country'];
            foreach ($allowedKeys as $key) {
                if (!empty($params[$key])) {
                    $filters[$key] = $params[$key];
                }
            }

            $response = $apiClient->get('smtp/emails/total', $filters);

            if (isset($response['total']) && is_numeric($response['total'])) {
                return (int) $response['total'];
            }

            throw new Exception('پاسخ نامعتبر از API دریافت شد');

        } catch (\Sendpulse\RestApi\ApiClientException $e) {
            error_log('SendPulse API Error (getTotalSentEmails): ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw new Exception('خطا در دریافت تعداد کل ایمیل‌ها: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('getTotalSentEmails Error: ' . $e->getMessage());
            throw $e;
        }
    }
}