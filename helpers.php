<?php

use Carbon\Carbon;
use Aws\S3\S3Client;
use App\Models\Device;
use App\Models\Position;
use App\Models\JobPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

function getNotificationCodes($type)
{
    $code = null;
    if ($type == 'account_review') {
        $code = 'ACR100';
    } elseif (in_array($type, ['event_created', 'event_updated', 'event_canceled', 'event_closed', 'event_begin', 'event_end'])) {
        $code = 'EVT100';
    } elseif (in_array($type, ['position_canceled'])) {
        $code = 'PO100';
    } elseif ($type = 'driver_load_assign') {
        $code = 'ALD100';
    } elseif (in_array($type, ['job_applied', 'job_canceled', 'job_completed', 'job_accepted', 'job_rejected', 'job_fired', 'job'])) {
        $code = 'JO100';
    } elseif ($type = 'review_posted') {
        $code = 'RE100';
    } elseif ($type = 'account_approved') {
        $code = 'AP100';
    } elseif ($type = 'common') {
        $code = 'CM100';
    }
    return $code;
}


function managerNotifications($message, $token, $body, $title,  $dataset = null, $type)
{

    $array = array();
    $config = config('app.manager_fcm_key');
    if ($type == 'driver') {
        $config = config('app.driver_firebase_fcm_token');
        array_push($array, $config);
    }
    $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    $headers = [
        'Authorization: key=' . $config,
        'Content-Type: application/json',
    ];

    // dd($message, $token/, $body, $title, $dataset);
    if (!empty($dataset)) {
        $message['dataset'] = $dataset;
    }
    $message['click_action'] = "FLUTTER_NOTIFICATION_CLICK";
    $fcmNotification = [
        "data" => $message,
        "notification" => [
            "body" => $body,
            "title" => !empty($title) ? $title : 'Show Time Staff',
            "badge" => "1",
            "sound" => "default",
        ],
        "registration_ids" => $token,
        "content_available" => true,
        "priority" => "high",
    ];
    if (env('APP_ENV') !== 'testing') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmNotification));
        $result = curl_exec($ch);
        if ($result === false) {
            die('Oops! FCM Send Error: ' . curl_error($ch));
        }
        Log::info([$result]);
        curl_close($ch);
    }
    return true;
}


function crewEventNotification($users_id = null, $code, $title, $message, $user_id = null, $event_type = null, $type = 'crew', $day_detail_id = null)
{
    $tokens = Device::whereIn('user_id', $users_id)->distinct()
        ->pluck('fcm_token')
        ->toArray();

    if (!empty($tokens)) {
        $body = $message;
        $message = [
            'code' => $code,
            'user_id' => (int) $user_id,
            'event_type' => $event_type,
            'day_detail_id' => $day_detail_id,
            // 'event_id' => $event_id,
        ];
        crewNotifications($message, $tokens, $body, $title, null, $type);
    }
}

function userAccountNotification($user_id = null, $code, $message, $title, $event_id = null, $event_type = null, $type = 'crew')
{
    $tokens = Device::where('user_id', $user_id)->distinct()
        ->pluck('fcm_token')
        ->toArray();
    // dd($tokens);
    if (!empty($tokens)) {
        $body = $message;
        $message = [
            'code' => $code,
            // 'user_id' => $user_id,
            'event_type' => $event_type,
            // 'event_id' => $event_id,
        ];
        managerNotifications($message, $tokens, $body, $title, null, $type);
    }
}

function crewNotifications($message, $token, $body, $title,  $dataset = null, $type)
{

    $array = array();
    $config = config('app.crew_fcm_key');
    if ($type == 'driver') {
        $config = config('app.driver_firebase_fcm_token');
        array_push($array, $config);
    }
    $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    $headers = [
        'Authorization: key=' . $config,
        'Content-Type: application/json',
    ];
    // dd($message, $token/, $body, $title, $dataset);
    if (!empty($dataset)) {
        $message['dataset'] = $dataset;
    }

    $message['click_action'] = "FLUTTER_NOTIFICATION_CLICK";
    $fcmNotification = [
        "data" => $message,
        "notification" => [
            "body" => $body,
            "title" => !empty($title) ? $title : 'Show Time Staff',
            "badge" => "1",
            "sound" => "default",
        ],
        "registration_ids" => $token,
        "content_available" => true,
        "priority" => "high",
    ];
    if (env('APP_ENV') !== 'testing') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmNotification));
        $result = curl_exec($ch);
        if ($result === false) {
            die('Oops! FCM Send Error: ' . curl_error($ch));
        }
        Log::info([$result]);
        curl_close($ch);
    }
    return true;
}

function managerNotification($users_id = null, $code, $title, $message, $user_id = null, $event_type = null, $type = 'manager', $event_id = null)
{
    $tokens = Device::where('user_id', $users_id)->distinct()
        ->pluck('fcm_token')
        ->toArray();
    // dd($tokens);
    if (!empty($tokens)) {
        $body = $message;
        $message = [
            'code' => $code,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'event_id' => $event_id,
        ];


        managerNotifications($message, $tokens, $body, $title, null, $type);
    }
}

function get_document_link($document_file)
{
    $client = new S3Client([
        'region' => config('app.aws_default_region'),
        'version' => 'latest',
        'endpoint' => config('app.aws_endpoint'),
        'credentials' => [
            'key' => config('app.aws_access_key_id'),
            'secret' => config('app.aws_secret_access_key'),
        ],
    ]);

    $expiry = "+120 minutes";

    $command = $client->getCommand('GetObject', [
        'Bucket' => config('app.aws_bucket_private'),
        'Key'    => $document_file
    ]);

    $request = $client->createPresignedRequest($command, $expiry);
    return (string) $request->getUri();
}

function isSameDay($time1, $time2)
{
    $carbonTime1 = Carbon::parse($time1);
    $carbonTime2 = Carbon::parse($time2);

    // Add the times together
    $sum = $carbonTime1->addHours($carbonTime2->hour)->addMinutes($carbonTime2->minute);

    // Check if the result is within the same day
    if ($carbonTime1->isSameDay($sum)) {
        return true;
    }

    return false;
}

function get_job_preferences_name($job_preferences)
{
    $job_preferenceData =  explode(',', $job_preferences);
    $job_prefArr = array();
    if (!empty($job_preferenceData)) {
        foreach ($job_preferenceData as $job_preference) {
            $job_pref = JobPreference::where('id', $job_preference)->first();
            if (!empty($job_pref)) {
                array_push($job_prefArr, $job_pref->name);
            }
        }
    }
    return $job_prefArr;
}


function getHourlyRateByPosition($positionId)
{
    $jobPrefData = [];
    if (!empty($positionId)) {
        $positionData = Position::where('id', $positionId)->first();
        if (!empty($positionData)) {
            $jobPrefData =  JobPreference::where([['name', 'LIKE', $positionData->name], ['status', 'A']])->first();
        }
    }
    if (!empty($jobPrefData)) {
        return $jobPrefData->rate_to_crew;
    } else {
        return null;
    }
}
