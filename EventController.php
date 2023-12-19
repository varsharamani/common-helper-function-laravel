<?php

namespace App\Http\Controllers\API;

use PDF;
use DateTime;
use Exception;
use Carbon\Carbon;
use App\Models\Day;
use App\Models\Job;
use App\Models\User;
use App\Models\Event;
use App\Models\Review;
use App\Models\Position;
use Carbon\CarbonPeriod;
use App\Models\AppReview;
use App\Models\DayDetail;
use App\Mail\EventEditMail;
use App\Mail\EventCloseMail;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Mail\EventCancelMail;
use App\Models\CrewFavourite;
use App\Models\JobPreference;
use App\Mail\EventCreatedMail;
use App\Services\CommonService;
use App\Mail\PositionCancelMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\EventResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\UserCollection;
use App\Http\Resources\EventCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\AppReviewResource;
use App\Http\Resources\CrewFavoriteCollection;
use Illuminate\Support\Facades\Validator;
use App\Rules\EndDateNotBeforeArrivalDate;
use App\Jobs\CrewNotifyAfter24Hours;
use App\Rules\DayFromDateIsBetweenArrivalAndEndDate;

class EventController extends Controller
{
    public function addEvent(Request $request)
    {
        $currentDateTime = Carbon::now();
        $dateTimeAfter30Minutes = $currentDateTime->addMinutes(30);
        $dateTimeAfter30Minutes->second(0);
        $futureDate = date('Y-m-d H:i:s', strtotime($dateTimeAfter30Minutes));

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:1|max:80',
            'overview' => 'required|string|min:1|max:400',
            'location' => 'required|min:1|max:255',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            'from_date' => 'required|date_format:Y-m-d H:i:s|after:' . $futureDate,
            'to_date' => 'required|date_format:Y-m-d H:i:s|after:' . $request['from_date'],
            'positions.*.name' => 'sometimes|required|string|min:1|max:255',
            'positions.*.notes' => 'sometimes|nullable|string|min:1|max:255',
            'positions.*.arrival_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s|after_or_equal:' . $request['from_date'] . '|before_or_equal:' . $request['to_date'],
            'positions.*.end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s', new EndDateNotBeforeArrivalDate($request->all(), 'end_date')],
            'positions.*.job_instructions' => 'sometimes|nullable|string|max:400',

            'positions.*.days.*.from_date' => ['sometimes', 'required', 'date_format:Y-m-d', new DayFromDateIsBetweenArrivalAndEndDate($request->all())],
            'positions.*.days.*.to_date' => ['sometimes', 'required', 'date_format:Y-m-d', new EndDateNotBeforeArrivalDate($request->all(), 'to_date')],
            'positions.*.days.*.quantity' => 'sometimes|required|regex:/^[0-9]+$/',
            'positions.*.days.*.hours_per_one' => ['sometimes', 'nullable', 'regex:/^-?(?:\d+|\d*\.\d+)$/'],
            'positions.*.days.*.hourly_rate' => 'sometimes|nullable|regex:/^[0-9]+$/',
            'positions.*.days.*.from_time' => 'required|date_format:H:i:s',
            'positions.*.days.*.to_time' => 'required|date_format:H:i:s',
            'prioritize_favourite' => 'required|in:0,1'
        ], [
            'from_date.date_format' => 'The event start date field must match the format Y-m-d H:i:s.',
            'to_date.date_format' => 'The event end date field must match the format Y-m-d H:i:s.',
            'from_date.after' => 'Sorry, you cannot create a new event within next 30 minutes of the current time. Please try again.',
            'to_date.after' => 'The event end date field must be after event start date.',
            'positions.*.name' => 'The position name field is required',
            // 'positions.*.notes' => 'The positiofrom_daten location field is required',
            'positions.*.arrival_date.required' => 'The position arrival date field is required',
            'positions.*.end_date.required' => 'The position end date field is required',
            'positions.*.arrival_date.date_format' => 'The position arrival date field must match the format Y-m-d H:i:s.',
            'positions.*.end_date.date_format' => 'The position end date field must match the format Y-m-d H:i:s.',
            'positions.*.days.*.from_date.required' => 'The day from date field is required',
            'positions.*.days.*.to_date.required' => 'The day to date field is required',
            'positions.*.days.*.quantity.required' => 'The day quantity field is required',
            'positions.*.days.*.hours_per_one.required' => 'The day hours per one field is required',
            'positions.*.days.*.hourly_rate.required' => 'The day hourly rate field is required',
            'positions.*.days.*.from_time.required' => 'The day from time field is required',
            'positions.*.days.*.to_time.required' => 'The day to time field is required',
            'positions.*.days.*.from_date.date_format' => 'The day from date field must match the format Y-m-d',
            'positions.*.days.*.to_date.date_format' => 'The day to date field must match the format Y-m-d',
            'positions.*.days.*.quantity.regex' => 'The day quantity field is must be number',
            'positions.*.days.*.hours_per_one.regex' => 'The day hours per one field is must be a valid format',
            'positions.*.days.*.hourly_rate.regex' => 'The day hourly rate field is must be number',
            'positions.*.days.*.from_time.date_format' => 'The day from time field must match the format h:i A.',
            'positions.*.days.*.to_time.date_format' => 'The day to time field must match the format h:i A.',
            'prioritize_favourite.in' => 'The prioritize favourite value must be 0 or 1.'
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $request->all();
        DB::beginTransaction();
        try {
            $image = null;
            if ($request->file('image')) {
                $image = Storage::disk('spaces')->put(config('app.image_path'), $request->file('image'), 'private');
            }

            $event = Event::create($request->only('name', 'overview', 'location') + [
                'image' => $image,
                'user_id' =>  auth()->user()->id,
                'from_date' => date('Y-m-d H:i:s', strtotime($data['from_date'])),
                'to_date' => date('Y-m-d H:i:s', strtotime($data['to_date'])),
                'prioritize_favourite' => $data['prioritize_favourite']
            ]);

            if (!is_array($data['positions'])) {
                $data['positions'] = json_decode($data['positions'], true);
            }

            $positionArr = [];
            if ($event->id) {
                for ($i = 0; $i < count($data['positions']); $i++) {
                    $position = Position::create([
                        'event_id' => $event->id,
                        'name' => $data['positions'][$i]['name'],
                        'notes' => $data['positions'][$i]['notes'],
                        'arrival_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['arrival_date'])),
                        'end_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['end_date'])),
                        'job_instructions' => $data['positions'][$i]['job_instructions']
                    ]);
                    array_push($positionArr, $data['positions'][$i]['name']);
                    if ($position->id) {
                        if (array_key_exists('days', $data['positions'][$i])) {
                            if (!is_array($data['positions'][$i]['days'])) {
                                $data['positions'][$i]['days'] = json_decode($data['positions'][$i]['days'], true);
                            }

                            for ($j = 0; $j < count($data['positions'][$i]['days']); $j++) {

                                $dayData =  $data['positions'][$i]['days'][$j];
                                $date1 = Carbon::createFromFormat('Y-m-d', $data['positions'][$i]['days'][$j]['from_date']);
                                $date2 = Carbon::createFromFormat('Y-m-d', $data['positions'][$i]['days'][$j]['to_date']);

                                $fromTime = Carbon::parse($data['positions'][$i]['days'][$j]['from_time']);
                                $toTime = Carbon::parse($data['positions'][$i]['days'][$j]['to_time']);

                                $fromHour = intval(date('H', strtotime($fromTime)));
                                $fromMinute = intval(date('i', strtotime($fromTime)));

                                $toHour = intval(date('H', strtotime($toTime)));
                                $toMinute = intval(date('i', strtotime($toTime)));

                                $carbonFromDate = Carbon::parse($date1);
                                $carbonToDate = Carbon::parse($date2);

                                $carbonToDateInclusive = $carbonToDate->addDay();

                                $totalDays = $carbonFromDate->diffInDays($carbonToDateInclusive);

                                // DB::enableQueryLog();

                                $eventData = Event::where('id', '!=', $event->id)->where('user_id', auth()->user()->id)->where('status', 'O')->where('location', $data['location'])
                                    ->whereHas('positions.days.day_details', function ($thisWherePositions) use ($dayData, $totalDays) {

                                        $thisWherePositions->checkTime($dayData, $totalDays);
                                    })->first();
                                // $query = DB::getQueryLog();
                                // dd($eventData);
                                // if (!empty($eventData)) {
                                //     return CommonService::errorResp('An event already exists at this time and place. Please choose a different place.', 400);
                                // } else {

                                $jobPrefData = [];
                                if ($position->id) {
                                    $positionData = Position::where('id', $position->id)->first();
                                    if (!empty($positionData)) {
                                        $jobPrefData =  JobPreference::where([['name', 'LIKE', $positionData->name], ['status', 'A']])->first();
                                    }
                                }
                                $day = Day::create([
                                    'position_id' => $position->id,
                                    'from_date' => date('Y-m-d', strtotime($date1)),
                                    'to_date' => date('Y-m-d', strtotime($date2)),
                                    'quantity' => $data['positions'][$i]['days'][$j]['quantity'],
                                    'hours_per_one' => $data['positions'][$i]['days'][$j]['hours_per_one'],
                                    'hourly_rate' => $data['positions'][$i]['days'][$j]['hourly_rate'],
                                    'from_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                    'to_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                ]);
                                // $day->hourly_rate = !empty($jobPrefData) ? $jobPrefData['rate_to_crew'] : null;
                                if ($totalDays > 0) {
                                    for ($k = 0; $k < $totalDays; $k++) {
                                        if ($fromHour < $toHour || ($fromHour === $toHour && $fromMinute < $toMinute)) {
                                            $from_date = clone $date1;
                                            $from_date->addDays($k);

                                            DayDetail::create([
                                                'day_id' => $day->id,
                                                'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                'to_date' => date('Y-m-d', strtotime($from_date))  . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                            ]);
                                        } else {
                                            $from_date = clone $date1;
                                            $from_date->addDays($k);
                                            $to_date = clone $date1;
                                            $to_date->addDays($k + 1);

                                            DayDetail::create([
                                                'day_id' => $day->id,
                                                'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                'to_date' => date('Y-m-d', strtotime($to_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                            ]);
                                        }
                                    }
                                }
                                // }
                            }
                        }
                    }
                }
            }


            // var_dump($data['prioritize_favourite']);
            $eventData = Event::where('id', $event->id)->with(['positions' => function ($thisPositions) {
                $thisPositions->with('days');
            }])->first();

            $data["title"] = "New Created Event Billing Information";
            $data["body"] = "New created event billing information";
            // $eventData =  new EventResource($eventData);
            // dd($eventData);
            if (!empty($eventData)) {

                if (!empty($eventData->positions)) {
                    foreach ($eventData->positions as $position) {

                        if (!empty($position->days)) {
                            foreach ($position->days as $day) {
                                $jobPrefData = [];

                                if ($day->position_id) {
                                    $positionData = Position::where('id', $day->position_id)->first();
                                    if (!empty($positionData)) {
                                        $jobPrefData =  JobPreference::where([['name', 'LIKE', $positionData->name], ['status', 'A']])->first();
                                    }
                                }
                                unset($day['hourly_rate']);
                                if (!empty($jobPrefData)) {


                                    $day->hourly_rate =  $jobPrefData->rate_to_crew;
                                }
                            }
                        }
                    }
                }
            }

            $pdf = PDF::loadView('emails.new_event_billing', compact('data', 'eventData'));

            Mail::send('emails.new_event_billing', compact('data', 'eventData'), function ($message) use ($data, $pdf) {
                $message->to(auth()->user()->email)
                    ->subject($data["title"])
                    ->attachData($pdf->output(), "event_billing.pdf");
            });

            $positionArr = array_unique($positionArr);
            $jobPrefId = [];
            foreach ($positionArr as $position) {
                $jobPrefData = JobPreference::where('name', '=', $position)->first();
                if (!empty($jobPrefData)) {
                    array_push($jobPrefId, $jobPrefData->id);
                }
            }


            if (!empty($jobPrefId)) {
                // $jobPrefId  = implode(",", $jobPrefId);

                if ($data['prioritize_favourite'] == 1) {

                    $favCrews = CrewFavourite::where([['manager_id', auth()->user()->id], ['is_favourite', 1]])->distinct()->pluck('user_id')->toArray();
                    if (!empty($favCrews)) {

                        $emails = User::where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->whereIn('id', $favCrews)->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })->distinct()
                            ->pluck('email')
                            ->toArray();
                        $crewIds = User::where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->whereIn('id', $favCrews)->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })->distinct()
                            ->pluck('id')
                            ->toArray();
                        $crewIdsArr = User::where([['role', '=', 'C'], ['status', '=', 'A']])->whereIn('id', $favCrews)->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })->distinct()
                            ->pluck('id')
                            ->toArray();
                        CrewNotifyAfter24Hours::dispatch($eventData, $jobPrefId)->delay(now()->addDay());
                    } else {
                        $emails = User::where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })
                            ->distinct()
                            ->pluck('email')
                            ->toArray();

                        $crewIds = User::where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })->distinct()
                            ->pluck('id')
                            ->toArray();
                        $crewIdsArr = User::where([['role', '=', 'C'], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                            foreach ($jobPrefId as $job_preference) {
                                $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                            }
                        })->distinct()
                            ->pluck('id')
                            ->toArray();
                    }
                } else {
                    $emails = User::where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                        foreach ($jobPrefId as $job_preference) {
                            $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                        }
                    })
                        ->distinct()
                        ->pluck('email')
                        ->toArray();

                    $crewIds = User::where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                        foreach ($jobPrefId as $job_preference) {
                            $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                        }
                    })->distinct()
                        ->pluck('id')
                        ->toArray();
                    $crewIdsArr = User::where([['role', '=', 'C'], ['status', '=', 'A']])->where(function ($query) use ($jobPrefId) {
                        foreach ($jobPrefId as $job_preference) {
                            $query->orWhere('job_preference', 'like', '%' . $job_preference . '%');
                        }
                    })->distinct()
                        ->pluck('id')
                        ->toArray();
                }

                if (!empty($emails)) {
                    Mail::to($emails)->send(new EventCreatedMail($eventData));
                }
                if (!empty($crewIds)) {
                    crewEventNotification($crewIds, getNotificationCodes('event_created'), 'New Event Posted!', "Event $eventData->name posted!. Don't miss out on this exciting work opportunity! View the details now and apply ASAP to secure your spot.", '', 'event_created');
                }
                if (!empty($crewIdsArr)) {
                    $notificationData = [];
                    $eventName = str_replace(' ', ' #', $eventData->name);
                    foreach ($crewIdsArr as $crew) {
                        $data = [
                            'user_id' => $crew,
                            'title' => "New Event Posted!",
                            'message' => "Event #$eventName posted!. Don't miss out on this exciting work opportunity! View the details now and apply ASAP to secure your spot.",
                            'type' => 'event_created',
                            'image' => $eventData->image,
                            'event_id' => $eventData->id,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ];
                        array_push($notificationData, $data);
                    }
                    Notification::insert($notificationData);
                }
            }
            DB::commit();
            return CommonService::successResp('event create successfully ..', new EventResource($eventData), 201);
        } catch (Exception $e) {
            Log::info($e->getMessage());
            // echo $e->getMessage();
            DB::rollBack();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    // public function addEvent(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'name' => 'required|string|min:1|max:80',
    //         'overview' => 'required|string|min:1|max:400',
    //         'location' => 'required|min:1|max:255',
    //         'image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
    //         'from_date' => 'required|date_format:Y-m-d H:i:s',
    //         'to_date' => 'required|date_format:Y-m-d H:i:s',
    //         'positions.*.name' => 'sometimes|required|string|min:1|max:255',
    //         'positions.*.notes' => 'sometimes|nullable|string|min:1|max:255',
    //         'positions.*.arrival_date' => 'sometimes|required|date_format:Y-m-d H:i:s',
    //         // 'positions.*.arrival_date' => 'sometimes|required|date_format:Y-m-d H:i:s|after:' . $request['from_date'] . '|before:' . $request['to_date'],
    //         'positions.*.end_date' => 'sometimes|required|date_format:Y-m-d H:i:s',
    //         'positions.*.job_instructions' => 'sometimes|nullable|string|max:400',
    //         'positions.*.days.*.from_date' => 'sometimes|required|date_format:Y-m-d',
    //         'positions.*.days.*.to_date' => 'sometimes|required|date_format:Y-m-d',
    //         'positions.*.days.*.quantity' => 'sometimes|required|regex:/^[0-9]+$/',
    //         'positions.*.days.*.hours_per_one' => ['sometimes', 'required', 'regex:/^-?(?:\d+|\d*\.\d+)$/'],
    //         'positions.*.days.*.hourly_rate' => 'sometimes|required|regex:/^[0-9]+$/',
    //         'positions.*.days.*.from_time' => 'required|date_format:h:i A',
    //         'positions.*.days.*.to_time' => 'required|date_format:h:i A',
    //     ], [
    //         'from_date.date_format' => 'The from date field must match the format Y-m-d H:i:s.',
    //         'to_date' => 'The to date field must match the format Y-m-d H:i:s.',
    //         'positions.*.name' => 'The position name field is required',
    //         'positions.*.arrival_date.required' => 'The position arrival date field is required',
    //         'positions.*.end_date.required' => 'The position end date field is required',
    //         'positions.*.arrival_date.date_format' => 'The position arrival date field must match the format Y-m-d H:i:s.',
    //         'positions.*.end_date.date_format' => 'The position end date field must match the format Y-m-d H:i:s.',
    //         'positions.*.days.*.from_date.required' => 'The day from date field is required',
    //         'positions.*.days.*.to_date.required' => 'The day to date field is required',
    //         'positions.*.days.*.quantity.required' => 'The day quantity field is required',
    //         'positions.*.days.*.hours_per_one.required' => 'The day hours per one field is required',
    //         'positions.*.days.*.hourly_rate.required' => 'The day hourly rate field is required',
    //         'positions.*.days.*.from_time.required' => 'The day from time field is required',
    //         'positions.*.days.*.to_time.required' => 'The day to time field is required',
    //         'positions.*.days.*.from_date.date_format' => 'The day from date field must match the format Y-m-d',
    //         'positions.*.days.*.to_date.date_format' => 'The day to date field must match the format Y-m-d',
    //         'positions.*.days.*.quantity.regex' => 'The day quantity field is must be number',
    //         'positions.*.days.*.hours_per_one.regex' => 'The day hours per one field is must be a valid format',
    //         'positions.*.days.*.hourly_rate.regex' => 'The day hourly rate field is must be number',
    //         'positions.*.days.*.from_time.date_format' => 'The day from time field must match the format h:i A.',
    //         'positions.*.days.*.to_time.date_format' => 'The day to time field must match the format h:i A.',
    //     ]);

    //     if ($validator->fails()) {
    //         return CommonService::errorResp($validator->errors()->first(), 422);
    //     }

    //     $data = $request->all();
    //     DB::beginTransaction();
    //     try {
    //         $image = null;
    //         if ($request->file('image')) {
    //             $image = Storage::disk('spaces')->put(config('app.image_path'), $request->file('image'), 'private');
    //         }

    //         $event = Event::create($request->only('name', 'overview', 'location') + [
    //             'image' => $image,
    //             'user_id' => auth()->user()->id,
    //             'from_date' => date('Y-m-d H:i:s', strtotime($data['from_date'])),
    //             'to_date' => date('Y-m-d H:i:s', strtotime($data['to_date']))
    //         ]);

    //         if (!is_array($data['positions'])) {
    //             $data['positions'] = json_decode($data['positions'], true);
    //         }
    //         if ($event->id) {
    //             for ($i = 0; $i < count($data['positions']); $i++) {
    //                 $position = Position::create([
    //                     'event_id' => $event->id,
    //                     'name' => $data['positions'][$i]['name'],
    //                     'notes' => $data['positions'][$i]['notes'],
    //                     'arrival_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['arrival_date'])),
    //                     'end_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['end_date'])),
    //                     'job_instructions' => $data['positions'][$i]['job_instructions']
    //                 ]);

    //                 if ($position->id) {
    //                     if (array_key_exists('days', $data['positions'][$i])) {
    //                         if (!is_array($data['positions'][$i]['days'])) {
    //                             $data['positions'][$i]['days'] = json_decode($data['positions'][$i]['days'], true);
    //                         }
    //                         for ($j = 0; $j < count($data['positions'][$i]['days']); $j++) {
    //                             $dayData =  $data['positions'][$i]['days'][$j];
    //                             $eventData = Event::where('id', '!=', $event->id)->where('user_id', auth()->user()->id)->where('status', 'O')->where('location', $data['location'])
    //                                 ->whereHas('positions', function ($thisWherePositions) use ($dayData) {
    //                                     $thisWherePositions->whereHas('days', function ($thisWhereDays) use ($dayData) {
    //                                         $thisWhereDays->checkTime($dayData);
    //                                     });
    //                                 })->first();

    //                             if (!empty($eventData)) {
    //                                 return CommonService::errorResp('An event already exists at this time and place. Please choose a different place.', 400);
    //                             } else {
    //                                 $day = Day::create([
    //                                     'position_id' => $position->id,
    //                                     'from_date' => $data['positions'][$i]['days'][$j]['from_date'],
    //                                     'to_date' => $data['positions'][$i]['days'][$j]['to_date'],
    //                                     'quantity' => $data['positions'][$i]['days'][$j]['quantity'],
    //                                     'hourly_rate' => $data['positions'][$i]['days'][$j]['hourly_rate'],
    //                                     'hours_per_one' => $data['positions'][$i]['days'][$j]['hours_per_one'],
    //                                     'from_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
    //                                     'to_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
    //                                 ]);
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //         DB::commit();
    //         $eventData = Event::where('id', $event->id)->with(['positions' => function ($thisPositions) {
    //             $thisPositions->with('days');
    //         }])->first();
    //         return CommonService::successResp('Event create successfully', new EventResource($eventData), 201);
    //     } catch (Exception $e) {
    //         echo $e->getMessage();
    //         DB::rollBack();
    //         return CommonService::errorResp('Something went wrong!', 500);
    //     }
    // }

    public function getEventsByManager(Request $request)
    {
        if (auth()->user()->role === 'M') {
            $filterData = $request->all();
            $pageSize = array_key_exists('limit', $filterData) ? intval($filterData['limit']) : null;

            if (array_key_exists('status', $filterData) && $filterData['status'] !== '') {
                if ($filterData['status'] === 'completed') {
                    $status = 'CL';
                } else if ($filterData['status'] === 'canceled') {
                    $status = 'CA';
                } else if ($filterData['status'] === 'open') {
                    $status = 'O';
                }
            }

            if (empty($filterData['status'])) {
                $eventData = Event::where('user_id', auth()->user()->id)->with(['positions' => function ($thisPositions) {
                    $thisPositions->whereHas('days', function ($thisDays) {
                        $thisDays->orderBy('created_at', 'ASC');
                    });
                }]);
            } else {
                $eventData = Event::where([['user_id', auth()->user()->id], ['status', $status]])->with(['positions' => function ($thisPositions) {
                    $thisPositions->whereHas('days', function ($thisDays) {
                        $thisDays->orderBy('created_at', 'ASC');
                    });
                }]);
            }



            if (!empty($filterData['from_date'])) {
                $eventData = $eventData->whereDate('from_date', '>=', $filterData['from_date']);
            }
            if (!empty($filterData['to_date'])) {
                $eventData = $eventData->whereDate('from_date', '<=', $filterData['to_date']);
            }

            $eventData = $eventData->orderBy('from_date', 'ASC')
                ->paginate(!empty($pageSize) && $pageSize > 0 ? $pageSize : config('app.pagination_per_page'));

            if ($eventData) {
                foreach ($eventData as $event) {

                    $event->hire_count = Job::where([['event_id', $event->id], ['job_status', 'H']])->get()->count();
                    $quantity_count_event_wise = 0;
                    if ($event->positions) {
                        foreach ($event->positions as $position) {
                            $jobPrefData =  JobPreference::where('status', 'A')->Where('name', 'LIKE', $position->name)->first();
                            if (!empty($jobPrefData)) {
                                $position->icon = $jobPrefData->icon;
                            }
                            $quantity_count = 0;
                            if ($position->days) {
                                foreach ($position->days as $day) {
                                    $dayDetailCount = DayDetail::where('day_id', $day->id)->get()->count();

                                    if ($day->quantity > 0) {
                                        $quantity_count += ($day->quantity * $dayDetailCount);
                                        $quantity_count_event_wise += ($day->quantity * $dayDetailCount);
                                        $day->quantity = ($day->quantity * $dayDetailCount);
                                    }
                                    $day->hire_count = Job::where([['event_id', $event->id], ['position_id', $position->id], ['day_id', $day->id], ['job_status', 'H']])->get()->count();
                                }
                            }
                            $position->quantity_count = $quantity_count;
                        }
                    }
                    $event->quantity_count = $quantity_count_event_wise;
                }
            }
            $eventData->today_event_count =  Event::WhereRaw(
                "'" . date("Y-m-d") . "'" . " BETWEEN DATE(from_date) and DATE(to_date)  and status='O' and user_id=" . auth()->user()->id . ""
            )->get()->count();

            $eventData->appends(request()->query());
            return CommonService::successResp('event data get successfully ..',  new EventCollection($eventData), 201);
        } else {
            return CommonService::errorResp("crew member doesn't have access of this data!", 500);
        }
    }

    public function getEventById(string $id)
    {

        try {
            $event = Event::where([['id', $id]])->with(['positions' => function ($thisPositions) {
                $thisPositions->with('days');
            }])->first();

            if (!empty($event)) {
                $event->hire_count = Job::where([['event_id', $id], ['job_status', 'H']])->get()->count();
                $quantity_count_event_wise = 0;
                if ($event->positions) {
                    foreach ($event->positions as $position) {
                        $jobPrefData =  JobPreference::where('status', 'A')->Where('name', 'LIKE', $position->name)->first();
                        if (!empty($jobPrefData)) {
                            $position->icon = $jobPrefData->icon;
                        }
                        $quantity_count = 0;
                        if ($position->days) {
                            foreach ($position->days as $day) {
                                if ($day->quantity > 0) {
                                    $quantity_count += $day->quantity;
                                    $quantity_count_event_wise += $day->quantity;
                                }
                                $day->hire_count = Job::where([['event_id', $id], ['position_id', $position->id], ['day_id', $day->id], ['job_status', 'H']])->get()->count();
                            }
                        }
                        $position->quantity_count = $quantity_count;
                    }
                }
                $event->quantity_count = $quantity_count_event_wise;
                return CommonService::successResp('', new EventResource($event), 201);
            } else {
                return CommonService::errorResp("Event doesn't found", 500);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function eventClose(string $id)
    {
        if (auth()->user()->role === 'M') {
            $eventData = Event::where('id', '=', $id)->with('user')->first();
            if ($eventData) {
                if ($eventData->user->id != auth()->user()->id) {
                    return CommonService::errorResp("You cannot access this event.!", 400);
                }
                $event = Event::where('id', '=', $id)->update(array('status' => 'CL'));
                $jobData = Job::where('event_id', $id)->whereIn('job_status', ['H', 'COM'])->pluck('user_id')
                    ->toArray();
                if (!empty($jobData)) {
                    $hiredCrew = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                    if (!empty($hiredCrew)) {
                        Mail::to($hiredCrew)->send(new EventCloseMail($eventData));
                    }
                }
                return CommonService::successResp('event successfully closed..', [], 201);
            } else {
                return CommonService::errorResp("event doesn't exists!", 400);
            }
        } else {
            return CommonService::errorResp("crew member doesn't have access of this data!", 500);
        }
    }

    public function getEventWithAvailableCrew(string $id)
    {
        if (auth()->user()->role === 'M') {
            $eventData = Event::where([['id', $id]])->with(['positions' => function ($thisPositions) {
                $thisPositions->with('days');
            }])->first();

            if (!empty($eventData)) {
                $eventData->hire_count = Job::where([['event_id', $id], ['job_status', 'H']])->get()->count();
                $quantity_count_event_wise = 0;
                foreach ($eventData->positions as $posVal) {
                    $quantity_count = 0;
                    foreach ($posVal->days as  $k => $dayVal) {
                        $dayDetailCount = DayDetail::where('day_id', $dayVal->id)->get()->count();
                        // if ($dayVal->id === 344) {
                        //     dd($dayVal->quantity, 'fg', $dayDetailCount);
                        // }
                        if ($dayVal->quantity > 0) {
                            $quantity_count +=  ($dayVal->quantity * $dayDetailCount);
                            $quantity_count_event_wise += ($dayVal->quantity * $dayDetailCount);
                            $dayVal->quantity = ($dayVal->quantity * $dayDetailCount);
                        }
                        $jobPrefData =  JobPreference::where('status', 'A')->Where('name', 'LIKE', $posVal->name)->first();
                        if (!empty($jobPrefData)) {
                            $posVal->icon = $jobPrefData->icon;
                        }
                        $dayVal->hire_count = Job::where([['event_id', $id], ['position_id', $posVal->id], ['day_id', $dayVal->id], ['job_status', 'H']])->get()->count();
                        if (!empty($jobPrefData)) {
                            $users = User::where([['role', '=', 'C'], ['status', '=', 'A']])->whereRaw('FIND_IN_SET("' . $jobPrefData->id . '",job_preference)')->with('crewFavourite', function ($query) {
                                $query->where('manager_id', auth()->user()->id);
                            })->get();
                            $availableUser = array();
                            if ($users) {
                                foreach ($users as $user) {
                                    $job = Job::where([['user_id', $user->id], ['event_id', $id], ['position_id', $posVal->id], ['day_id', $dayVal->id]])->first();
                                    $hireDayDetailArr = [];
                                    if (!empty($job)) {

                                        $user->job_status = $job->job_status;
                                        if ($job->job_status === 'H') {
                                            $hireDayDetailArr = Job::where([['user_id', $user->id], ['event_id', $id], ['position_id', $posVal->id], ['day_id', $dayVal->id], ['job_status', 'H']])->pluck('day_detail_id')->toArray();

                                            $user->hire_day_details = DayDetail::whereIn('id', $hireDayDetailArr)->get();
                                        }
                                    }
                                    $user->rating =  Review::where('user_id', $user->id)->pluck('rating')->avg();
                                    $user->user_reviews = Review::where('user_id', $user->id)->get();
                                    $userJob = Job::where([['user_id', $user->id], ['day_id', '!=', $dayVal->id], ['job_status', 'H']])->get();
                                    // if ($dayVal->id === 343 && $user->id === 21) {

                                    //     dd($hireDayDetailArr);
                                    // }
                                    $flag = 0;
                                    if (empty($hireDayDetailArr)) {
                                        if (!empty($userJob)) {
                                            foreach ($userJob as $job) {
                                                DB::enableQueryLog();
                                                $jobDayData = DayDetail::where('day_id', $job->day_id)->where(function ($query) use ($dayVal) {
                                                    $query->checkEventTime($dayVal)->first();
                                                })->first();
                                                // if ($job->day_id === 342 && $job->user_id === 21) {
                                                //     dd($userJob);
                                                // }
                                                if (!empty($jobDayData)) {
                                                    $flag = 1;
                                                }
                                            }
                                        }
                                        if ($flag === 0) {
                                            array_push($availableUser, $user);
                                        }
                                    } else {
                                        array_push($availableUser, $user);
                                    }
                                }
                            }
                            $dayVal->available_crews = new UserCollection($availableUser);
                        }
                    }
                    $posVal->quantity_count = $quantity_count;
                }
                $eventData->quantity_count = $quantity_count_event_wise;
            }

            if ($eventData) {
                return CommonService::successResp('event data get successfully ..', new EventResource($eventData), 201);
            } else {
                return CommonService::errorResp("event doesn't exists!", 400);
            }
        } else {
            return CommonService::errorResp("crew member doesn't have access of this data!", 500);
        }
    }

    public function eventCancel(string $id)
    {
        // try {
        if (auth()->user()->role === 'M') {
            $eventData = Event::where('id', '=', $id)->with('user')->first();
            if ($eventData) {
                if ($eventData->user->id != auth()->user()->id) {
                    return CommonService::errorResp("You cannot access this event.!", 400);
                }
                $event = Event::where('id', '=', $id)->update(array('status' => 'CA'));
                $jobData = Job::where('event_id', $id)->whereIn('job_status', ['H', 'IR'])->pluck('user_id')
                    ->toArray();
                if (!empty($jobData)) {
                    $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                    if (!empty($hiredCrewEmailNotify)) {
                        Mail::to($hiredCrewEmailNotify)->send(new EventCancelMail($eventData));
                    }
                    $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                    if (!empty($hiredCrewPushNotify)) {
                        crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_canceled'), 'Event Cancellation Notice', "The event $eventData->name you applied to has been canceled. We apologize for any inconvenience. Stay tuned for future work opportunities.", '', 'event_canceled');
                    }
                    $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                    if (!empty($hiredCrews)) {

                        $notificationData = [];
                        $eventName = str_replace(' ', ' #', $eventData->name);
                        foreach ($hiredCrews as $hireCrew) {
                            $data = [
                                'user_id' => $hireCrew,
                                'title' => "Event Cancellation Notice",
                                'message' => "The event #$eventName you applied to has been canceled. We apologize for any inconvenience. Stay tuned for future work opportunities.",
                                'type' => 'event_canceled',
                                'image' => $eventData->image,
                                'event_id' => $eventData->id,
                                'reference_id' => auth()->user()->id,
                                'reference_type' => 'manager',
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                            array_push($notificationData, $data);
                        }
                        Notification::insert($notificationData);
                    }
                }
                Job::where('event_id', $id)->delete();
                return CommonService::successResp('event successfully canceled..', [], 201);
            } else {
                return CommonService::errorResp("event doesn't exists!", 400);
            }
        } else {
            return CommonService::errorResp("crew member doesn't have access of this data!", 500);
        }
        // } catch (Exception $e) {
        //     echo $e->getMessage();
        //     return CommonService::errorResp('Something went wrong!', 500);
        // }
    }

    public function addCrewReviewRating(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'manager_id' => 'required',
            'event_id' => 'required',
            'position_id' => 'required',
            'day_id' => 'required',
            'rating' => 'nullable|numeric|max:5',
            'review' => 'nullable'
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $review = Review::where([['user_id', $data['user_id']], ['manager_id', $data['manager_id']], ['event_id', $data['event_id']], ['position_id', $data['position_id']], ['day_id', $data['day_id']]])->first();
            if ($review) {
                $review = Review::where([['user_id', $data['user_id']], ['manager_id', $data['manager_id']], ['event_id', $data['event_id']], ['position_id', $data['position_id']], ['day_id', $data['day_id']]])->update($request->only('rating', 'review'));
                return CommonService::successResp('review data updated successfully ..', [], 201);
            } else {
                $review = Review::create($request->only('user_id', 'manager_id', 'event_id', 'position_id', 'day_id', 'rating', 'review'));
                return CommonService::successResp('review data add successfully ..', new ReviewResource($review), 201);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function editEvent(Request $request)
    {
        $currentDateTime = Carbon::now();
        $dateTimeAfter30Minutes = $currentDateTime->addMinutes(30);
        $dateTimeAfter30Minutes->second(0);
        $futureDate = date('Y-m-d H:i:s', strtotime($dateTimeAfter30Minutes));

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:1|max:80',
            'overview' => 'required|string|min:1|max:400',
            'location' => 'required|min:1|max:255',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            'from_date' => 'required|date_format:Y-m-d H:i:s|after:' . $futureDate,
            'to_date' => 'required|date_format:Y-m-d H:i:s|after:' . $request['from_date'],
            'positions.*.name' => 'sometimes|required|string|min:1|max:255',
            'positions.*.notes' => 'sometimes|nullable|string|min:1|max:255',
            'positions.*.arrival_date' => 'sometimes|required|date_format:Y-m-d H:i:s|after_or_equal:' . $request['from_date'] . '|before_or_equal:' . $request['to_date'],
            'positions.*.end_date' =>  ['sometimes', 'required', 'date_format:Y-m-d H:i:s', new EndDateNotBeforeArrivalDate($request->all(), 'end_date')],
            'positions.*.job_instructions' => 'sometimes|nullable|string|max:400',
            'positions.*.days.*.from_date' => ['sometimes', 'required', 'date_format:Y-m-d', new DayFromDateIsBetweenArrivalAndEndDate($request->all())],
            'positions.*.days.*.to_date' => ['sometimes', 'required', 'date_format:Y-m-d', new EndDateNotBeforeArrivalDate($request->all(), 'to_date')],
            'positions.*.days.*.quantity' => 'sometimes|required|regex:/^[0-9]+$/',
            'positions.*.days.*.hours_per_one' => ['sometimes', 'nullable', 'regex:/^-?(?:\d+|\d*\.\d+)$/'],
            'positions.*.days.*.hourly_rate' => 'sometimes|nullable|regex:/^[0-9]+$/',
            'positions.*.days.*.from_time' => 'sometimes|required|date_format:h:i A',
            'positions.*.days.*.to_time' => 'sometimes|required|date_format:h:i A',
            'delete_positions' => "nullable",
            'delete_days' => "nullable",
        ], [
            'from_date.date_format' => 'The event start date field must match the format Y-m-d H:i:s.',
            'to_date.date_format' => 'The event end date field must match the format Y-m-d H:i:s.',
            'from_date.after' => 'Sorry, you cannot create a new event within next 30 minutes of the current time. Please try again.',
            'to_date.after' => 'The event end date field must be after event start date.',
            'positions.*.name' => 'The position name field is required',
            // 'positions.*.location' => 'The position location field is required',
            'positions.*.arrival_date.required' => 'The position arrival date field is required',
            'positions.*.end_date.required' => 'The position end date field is required',
            'positions.*.arrival_date.date_format' => 'The position arrival date field must match the format Y-m-d H:i:s.',
            'positions.*.end_date.date_format' => 'The position end date field must match the format Y-m-d H:i:s.',
            'positions.*.days.*.from_date.required' => 'The day from date field is required',
            'positions.*.days.*.to_date.required' => 'The day to date field is required',
            'positions.*.days.*.quantity.required' => 'The day quantity field is required',
            'positions.*.days.*.hours_per_one.required' => 'The day hours per one field is required',
            'positions.*.days.*.hourly_rate.required' => 'The day hourly rate field is required',
            'positions.*.days.*.from_time.required' => 'The day from time field is required',
            'positions.*.days.*.to_time.required' => 'The day to time field is required',
            'positions.*.days.*.from_date.date_format' => 'The day from date field must match the format Y-m-d',
            'positions.*.days.*.to_date.date_format' => 'The day to date field must match the format Y-m-d',
            'positions.*.days.*.quantity.regex' => 'The day quantity field is must be number',
            'positions.*.days.*.hours_per_one.regex' => 'The day hours per one field is must be a valid format',
            'positions.*.days.*.hourly_rate.regex' => 'The day hourly rate field is must be number',
            'positions.*.days.*.from_time.date_format' => 'The day from time field must match the format h:i A.',
            'positions.*.days.*.to_time.date_format' => 'The day to time field must match the format h:i A.',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }


        $data = $request->all();

        // Log::debug(print_r($data, true));
        DB::beginTransaction();
        // try {

        $eventData = Event::where('id', $data['id'])->with(['positions' => function ($thisPositions) {
            $thisPositions->with('days');
        }])->first();
        if (!empty($eventData)) {
            $image = $eventData->image;
            if ($request->file('image')) {
                $image = Storage::disk('spaces')->put(config('app.image_path'), $request->file('image'), 'private');
            }

            $event = Event::where('id', $data['id'])->update($request->only('name', 'overview', 'location') + [
                'image' => $image,
                'user_id' =>  auth()->user()->id,
                'from_date' => date('Y-m-d H:i:s', strtotime($data['from_date'])),
                'to_date' => date('Y-m-d H:i:s', strtotime($data['to_date']))
            ]);

            if (!is_array($data['delete_days'])) {
                $data['delete_days'] = json_decode($data['delete_days'], true);
            }


            if (!empty($data['delete_days'])) {
                foreach ($data['delete_days'] as $dayId) {
                    DayDetail::where('day_id', $dayId)->delete();
                    Job::where('day_id', $dayId)->delete();
                    Day::where('id', $dayId)->delete();
                }
            }


            if (!is_array($data['delete_positions'])) {
                $data['delete_positions'] = json_decode($data['delete_positions'], true);
            }


            if (!empty($data['delete_positions'])) {
                foreach ($data['delete_positions'] as $positionId) {
                    $positionData = Position::where('id', $positionId)->first();
                    if (!empty($positionData)) {
                        $eventData = Event::where('id', $positionData->event_id)->first();
                        if (!empty($eventData)) {
                            $position = Position::where('id', $positionId)->delete();
                            $jobData = Job::where([['position_id', '=', $positionId], ['job_status', '=', 'H']])->pluck('user_id')
                                ->toArray();
                            if (!empty($jobData)) {
                                $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                                if (!empty($hiredCrewEmailNotify)) {
                                    Mail::to($hiredCrewEmailNotify)->send(new PositionCancelMail($positionData, $eventData));
                                }
                                $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                if (!empty($hiredCrewPushNotify)) {
                                    crewEventNotification($hiredCrewPushNotify, getNotificationCodes('position_canceled'), 'Position Cancellation Notice', "The position $positionData->name you applied to has been canceled. We apologize for any inconvenience. Stay tuned for future work opportunities.", '', 'position_canceled');
                                }

                                $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                if (!empty($hiredCrews)) {

                                    $notificationData = [];
                                    $positionName = str_replace(' ', ' #', $positionData->name);
                                    foreach ($hiredCrews as $hireCrew) {
                                        $data = [
                                            'user_id' => $hireCrew,
                                            'title' => "Position Cancellation Notice",
                                            'message' => "The position #$positionName you applied to has been canceled. We apologize for any inconvenience. Stay tuned for future work opportunities.",
                                            'type' => 'position_canceled',
                                            'image' => $eventData->image,
                                            'event_id' => $eventData->id,
                                            'reference_id' => auth()->user()->id,
                                            'reference_type' => 'manager',
                                            'created_at' => Carbon::now(),
                                            'updated_at' => Carbon::now(),
                                        ];
                                        array_push($notificationData, $data);
                                    }
                                    Notification::insert($notificationData);
                                }
                            }
                            Job::where('position_id', $positionId)->delete();
                            if ($position) {
                                $dayData = Day::where('position_id', $positionId)->get();
                                if (!empty($dayData)) {
                                    foreach ($dayData as $day) {
                                        DayDetail::where('day_id', $day->id)->delete();
                                    }
                                }
                                Day::where('position_id', $positionId)->delete();
                            }
                        }
                    }
                }
            }


            if (!empty($data['positions']) && !is_array($data['positions'])) {
                $data['positions'] = json_decode($data['positions'], true);
            }


            if (!empty($data['positions']) && $data['id']) {
                for ($i = 0; $i < count($data['positions']); $i++) {
                    // dd(array_key_exists('id', $data['positions'][$i]) && $data['positions'][$i]['id'] !== '' && $data['positions'][$i]['id'] !== null);
                    if (array_key_exists('id', $data['positions'][$i]) && $data['positions'][$i]['id'] !== '' && $data['positions'][$i]['id'] !== null) {
                        $position = Position::where([['event_id', $data['id']], ['id', (int)$data['positions'][$i]['id']]])->update([
                            'name' => $data['positions'][$i]['name'],
                            'notes' => $data['positions'][$i]['notes'],
                            'arrival_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['arrival_date'])),
                            'end_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['end_date'])),
                            'job_instructions' => $data['positions'][$i]['job_instructions']
                        ]);
                        $position = (object)[];
                        $position->id = (int)$data['positions'][$i]['id'];
                    } else {
                        $position = Position::create([
                            'event_id' => $data['id'],
                            'name' => $data['positions'][$i]['name'],
                            'notes' => $data['positions'][$i]['notes'],
                            'arrival_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['arrival_date'])),
                            'end_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['end_date'])),
                            'job_instructions' => $data['positions'][$i]['job_instructions']
                        ]);
                    }
                    if ($position->id) {
                        if (array_key_exists('days', $data['positions'][$i])) {
                            if (!is_array($data['positions'][$i]['days'])) {
                                $data['positions'][$i]['days'] = json_decode($data['positions'][$i]['days'], true);
                            }
                            for ($j = 0; $j < count($data['positions'][$i]['days']); $j++) {

                                $dayData =  $data['positions'][$i]['days'][$j];
                                $date1 = Carbon::createFromFormat('Y-m-d', $data['positions'][$i]['days'][$j]['from_date']);
                                $date2 = Carbon::createFromFormat('Y-m-d', $data['positions'][$i]['days'][$j]['to_date']);

                                $fromTime = Carbon::parse($data['positions'][$i]['days'][$j]['from_time']);
                                $toTime = Carbon::parse($data['positions'][$i]['days'][$j]['to_time']);

                                $fromHour = intval(date('H', strtotime($fromTime)));
                                $fromMinute = intval(date('i', strtotime($fromTime)));

                                $toHour = intval(date('H', strtotime($toTime)));
                                $toMinute = intval(date('i', strtotime($toTime)));

                                $carbonFromDate = Carbon::parse($date1);
                                $carbonToDate = Carbon::parse($date2);

                                $carbonToDateInclusive = $carbonToDate->addDay();

                                $totalDays = $carbonFromDate->diffInDays($carbonToDateInclusive);

                                $existingEventData = Event::where('id', '!=',  $data['id'])->where('user_id', auth()->user()->id)->where('status', 'O')->where('location', $data['location'])
                                    ->whereHas('positions.days.day_details', function ($thisWherePositions) use ($dayData, $totalDays) {
                                        $thisWherePositions->checkTime($dayData, $totalDays);
                                    })->first();
                                // if (!empty($existingEventData)) {
                                //     return CommonService::errorResp('An event already exists at this time and place. Please choose a different place.', 400);
                                // } else {
                                if (array_key_exists('id', $data['positions'][$i]['days'][$j]) && $data['positions'][$i]['days'][$j]['id'] !== '' && $data['positions'][$i]['days'][$j]['id'] !== null) {

                                    $dayData = Day::where('id', $data['positions'][$i]['days'][$j]['id'])->first();
                                    $hiredData = Job::where([['day_id', $data['positions'][$i]['days'][$j]['id']], ['job_status', 'H']])->get();
                                    if (!$hiredData->isEmpty()) {
                                        if (
                                            $dayData->from_date !== $data['positions'][$i]['days'][$j]['from_date']
                                            || $dayData->to_date !== $data['positions'][$i]['days'][$j]['to_date']
                                            || $dayData->hourly_rate !== $data['positions'][$i]['days'][$j]['hourly_rate']
                                            || $dayData->from_time !== date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time']))
                                            || $dayData->to_time !== date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time']))
                                        ) {
                                            return CommonService::errorResp("event data doesn't edit because user already hired for this event .", 400);
                                        }
                                    }

                                    $day = Day::where([['position_id', $position->id], ['id', (int)$data['positions'][$i]['days'][$j]['id']]])->update([
                                        'from_date' => $data['positions'][$i]['days'][$j]['from_date'],
                                        'to_date' => $data['positions'][$i]['days'][$j]['to_date'],
                                        'quantity' => $data['positions'][$i]['days'][$j]['quantity'],
                                        'hours_per_one' => $data['positions'][$i]['days'][$j]['hours_per_one'],
                                        'hourly_rate' => $data['positions'][$i]['days'][$j]['hourly_rate'],
                                        'from_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                        'to_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                    ]);

                                    $day = (object)[];
                                    $day->id = (int)$data['positions'][$i]['days'][$j]['id'];


                                    // dd($dayData, '---', $data['positions'][$i]['days'][$j]);
                                    if (!empty($dayData)) {
                                        if ($dayData->from_date !== $data['positions'][$i]['days'][$j]['from_date'] || $dayData->to_date !== $data['positions'][$i]['days'][$j]['to_date'] || $dayData->from_time !== date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])) || $dayData->to_time !== date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time']))) {
                                            DayDetail::where('day_id', $data['positions'][$i]['days'][$j]['id'])->delete();

                                            if ($totalDays > 0) {
                                                for ($k = 0; $k < $totalDays; $k++) {
                                                    if ($fromHour < $toHour || ($fromHour === $toHour && $fromMinute < $toMinute)) {
                                                        $from_date = clone $date1;
                                                        $from_date->addDays($k);

                                                        DayDetail::create([
                                                            'day_id' => $day->id,
                                                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                            'to_date' => date('Y-m-d', strtotime($from_date))  . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                                        ]);
                                                    } else {
                                                        $from_date = clone $date1;
                                                        $from_date->addDays($k);
                                                        $to_date = clone $date1;
                                                        $to_date->addDays($k + 1);

                                                        DayDetail::create([
                                                            'day_id' => $day->id,
                                                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                            'to_date' => date('Y-m-d', strtotime($to_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                                        ]);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    $day = Day::create([
                                        'position_id' => $position->id,
                                        'from_date' => $data['positions'][$i]['days'][$j]['from_date'],
                                        'to_date' => $data['positions'][$i]['days'][$j]['to_date'],
                                        'quantity' => $data['positions'][$i]['days'][$j]['quantity'],
                                        'hours_per_one' => $data['positions'][$i]['days'][$j]['hours_per_one'],
                                        'hourly_rate' => $data['positions'][$i]['days'][$j]['hourly_rate'],
                                        'from_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                        'to_time' => date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                    ]);

                                    if ($totalDays > 0) {
                                        for ($k = 0; $k < $totalDays; $k++) {
                                            if ($fromHour < $toHour || ($fromHour === $toHour && $fromMinute < $toMinute)) {
                                                $from_date = clone $date1;
                                                $from_date->addDays($k);

                                                DayDetail::create([
                                                    'day_id' => $day->id,
                                                    'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                    'to_date' => date('Y-m-d', strtotime($from_date))  . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                                ]);
                                            } else {
                                                $from_date = clone $date1;
                                                $from_date->addDays($k);
                                                $to_date = clone $date1;
                                                $to_date->addDays($k + 1);

                                                DayDetail::create([
                                                    'day_id' => $day->id,
                                                    'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['from_time'])),
                                                    'to_date' => date('Y-m-d', strtotime($to_date)) . ' ' . date('H:i:s', strtotime($data['positions'][$i]['days'][$j]['to_time'])),
                                                ]);
                                            }
                                        }
                                    }
                                }
                                // }
                            }
                        }
                    }
                }
            }
            DB::commit();
            $updatedEvent = Event::where('id', $data['id'])->with(['positions' => function ($thisPositions) {
                $thisPositions->with('days');
            }])->first();

            if ($updatedEvent) {
                if ($eventData->location !== $updatedEvent->location || $eventData->from_date !== $updatedEvent->from_date || $eventData->to_date !== $updatedEvent->to_date) {
                    $jobData = Job::where([['event_id', '=', $data['id']], ['job_status', '=', 'H']])->pluck('user_id')
                        ->toArray();
                    if (!empty($jobData)) {
                        $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                        if (!empty($hiredCrewEmailNotify)) {
                            Mail::to($hiredCrewEmailNotify)->send(new EventEditMail($updatedEvent));
                        }
                        $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                        if (!empty($hiredCrewPushNotify)) {
                            crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_updated'), 'Event Updated', "An update has been made the event $eventData->name you're participating in. Please log in to the app to view the latest changes and instructions.", '', 'event_updated');
                        }
                        $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                        if (!empty($hiredCrews)) {

                            $notificationData = [];
                            $eventName = str_replace(' ', ' #', $eventData->name);
                            foreach ($hiredCrews as $hireCrew) {
                                $data = [
                                    'user_id' => $hireCrew,
                                    'title' => "Event Updated",
                                    'message' => "An update has been made the event #$eventName you're participating in. Please log in to the app to view the latest changes and instructions.",
                                    'type' => 'event_updated',
                                    'image' => $eventData->image,
                                    'event_id' => $eventData->id,
                                    'reference_id' => auth()->user()->id,
                                    'reference_type' => 'manager',
                                    'created_at' => Carbon::now(),
                                    'updated_at' => Carbon::now(),
                                ];
                                array_push($notificationData, $data);
                            }
                            Notification::insert($notificationData);
                        }
                    }
                } else {
                    if (!empty($eventData->positions)) {
                        foreach ($eventData->positions as $position) {
                            $updatedPosition = Position::where('id', $position->id)->first();
                            if (!empty($data['delete_positions']) && !in_array($position->id, $data['delete_positions'])) {
                                if ($position->name !== $updatedPosition->name || $position->arrival_date !== $updatedPosition->arrival_date || $position->end_date !== $updatedPosition->end_date || $position->job_instructions !== $updatedPosition->job_instructions) {
                                    $jobData = Job::where([['event_id', '=', $data['id']], ['position_id', '=', $position->id], ['job_status', '=', 'H']])->pluck('user_id')
                                        ->toArray();
                                    if (!empty($jobData)) {
                                        $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                                        if (!empty($hiredCrewEmailNotify)) {
                                            Mail::to($hiredCrewEmailNotify)->send(new EventEditMail($updatedEvent));
                                        }
                                        $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                        if (!empty($hiredCrewPushNotify)) {
                                            crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_updated'), 'Event Updated', "An update has been made the event $eventData->name you're participating in. Please log in to the app to view the latest changes and instructions.", '', 'event_updated');
                                        }
                                        $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                        if (!empty($hiredCrews)) {

                                            $notificationData = [];
                                            $eventName = str_replace(' ', ' #', $eventData->name);
                                            foreach ($hiredCrews as $hireCrew) {
                                                $data = [
                                                    'user_id' => $hireCrew,
                                                    'title' => "Event Updated",
                                                    'message' => "An update has been made the event #$eventName you're participating in. Please log in to the app to view the latest changes and instructions.",
                                                    'type' => 'event_updated',
                                                    'image' => $eventData->image,
                                                    'event_id' => $eventData->id,
                                                    'reference_id' => auth()->user()->id,
                                                    'reference_type' => 'manager',
                                                    'created_at' => Carbon::now(),
                                                    'updated_at' => Carbon::now(),
                                                ];
                                                array_push($notificationData, $data);
                                            }
                                            Notification::insert($notificationData);
                                        }
                                    }
                                } else {
                                    if (!empty($position->days)) {
                                        foreach ($position->days as $day) {
                                            $updatedDay = Day::where('id', $day->id)->first();
                                            if (!empty($data['delete_days']) && !in_array($day->id, $data['delete_days'])) {
                                                if ($day->from_date !== $updatedDay->from_date || $day->to_date !== $updatedDay->to_date || $day->hourly_rate !== $updatedDay->hourly_rate || $day->from_time !== $updatedDay->from_time || $day->to_time !== $updatedDay->to_time) {
                                                    $jobData = Job::where([['event_id', '=', $data['id']], ['position_id', '=', $position->id], ['day_id', '=', $day->id], ['job_status', '=', 'H']])->pluck('user_id')
                                                        ->toArray();
                                                    if (!empty($jobData)) {
                                                        $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                                                        if (!empty($hiredCrewEmailNotify)) {
                                                            Mail::to($hiredCrewEmailNotify)->send(new EventEditMail($updatedEvent));
                                                        }
                                                        $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                                        if (!empty($hiredCrewPushNotify)) {
                                                            crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_updated'), 'Event Updated', "An update has been made the event $eventData->name you're participating in. Please log in to the app to view the latest changes and instructions.", '', 'event_updated');
                                                        }
                                                        $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                                        if (!empty($hiredCrews)) {

                                                            $notificationData = [];
                                                            $eventName = str_replace(' ', ' #', $eventData->name);
                                                            foreach ($hiredCrews as $hireCrew) {
                                                                $data = [
                                                                    'user_id' => $hireCrew,
                                                                    'title' => "Event Updated",
                                                                    'message' => "An update has been made the event #$eventName you're participating in. Please log in to the app to view the latest changes and instructions.",
                                                                    'type' => 'event_updated',
                                                                    'image' => $eventData->image,
                                                                    'event_id' => $eventData->id,
                                                                    'reference_id' => auth()->user()->id,
                                                                    'reference_type' => 'manager',
                                                                    'created_at' => Carbon::now(),
                                                                    'updated_at' => Carbon::now(),
                                                                ];
                                                                array_push($notificationData, $data);
                                                            }
                                                            Notification::insert($notificationData);
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }


            return CommonService::successResp('event update successfully ..', new EventResource($updatedEvent), 201);
        } else {
            return CommonService::errorResp("event doesn't exists.", 400);
        }
        // } catch (Exception $e) {
        //     echo $e->getMessage();
        //     DB::rollBack();
        //     return CommonService::errorResp('Something went wrong!', 500);
        // }
    }

    public function addAppReview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable',
            'event_id' => 'required',
            'is_like' => 'nullable',
            'review' => 'nullable',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        try {
            $eventData = Event::where('id', '=', $data['event_id'])->first();
            if ($eventData) {
                if ($data['is_like'] === null && $data['review'] === null) {
                    $event = Event::where('id', '=', $data['event_id'])->update(array('status' => 'CL'));
                    $jobData = Job::where('event_id', '=', $data['event_id'])->whereIn('job_status', ['H', 'COM'])->pluck('user_id')
                        ->toArray();
                    if (!empty($jobData)) {
                        $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                        if (!empty($hiredCrewEmailNotify)) {
                            Mail::to($hiredCrewEmailNotify)->send(new EventCloseMail($eventData));
                        }
                        $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                        if (!empty($hiredCrewPushNotify)) {
                            crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_closed'), 'Pay and Work Summary Now Available', "The event $eventData->name has ended and your final pay and work summary are now available for review.", '', 'event_closed');
                        }
                        $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                        if (!empty($hiredCrews)) {

                            $notificationData = [];
                            $eventName = str_replace(' ', ' #', $eventData->name);
                            foreach ($hiredCrews as $hireCrew) {
                                $data = [
                                    'user_id' => $hireCrew,
                                    'title' => "Pay and Work Summary Now Available",
                                    'message' => "The event #$eventName has ended and your final pay and work summary are now available for review.",
                                    'type' => 'event_closed',
                                    'image' => $eventData->image,
                                    'event_id' => $eventData->id,
                                    'reference_id' => auth()->user()->id,
                                    'reference_type' => 'manager',
                                    'created_at' => Carbon::now(),
                                    'updated_at' => Carbon::now(),
                                ];
                                array_push($notificationData, $data);
                            }
                            Notification::insert($notificationData);
                        }
                    }
                    return CommonService::successResp('event close successfully ..', [], 201);
                } else {
                    $review = AppReview::where([['user_id', $data['user_id']], ['event_id', $data['event_id']]])->first();
                    $hiredCrewPushNotify = User::where('id', $data['user_id'])->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                    if (!empty($hiredCrewPushNotify)) {
                        crewEventNotification($hiredCrewPushNotify, getNotificationCodes('review_posted'), 'Review Posted', "A review has been posted. Log in to the app to read the review and see the feedback provided.", $eventData->user_id, 'review_posted');
                    }
                    $hiredCrews = User::where('id', $data['user_id'])->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                    if (!empty($hiredCrews)) {

                        $notificationData = [];
                        foreach ($hiredCrews as $hireCrew) {
                            $data = [
                                'user_id' => $hireCrew,
                                'title' => "Review Posted",
                                'message' => "A review has been posted. Log in to the app to read the review and see the feedback provided.",
                                'type' => 'review_posted',
                                'image' => auth()->user()->profile_photo,
                                'event_id' => $eventData->id,
                                'reference_id' => auth()->user()->id,
                                'reference_type' => 'manager',
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                            array_push($notificationData, $data);
                        }
                        Notification::insert($notificationData);
                    }
                    if ($review) {
                        $review = AppReview::where([['user_id', $data['user_id']], ['event_id', $data['event_id']]])->update($request->only('is_like', 'review'));
                        return CommonService::successResp('review data updated successfully..', [], 201);
                    } else {
                        $review = AppReview::create($request->only('user_id', 'event_id', 'is_like', 'review'));
                        return CommonService::successResp('review data add successfully ..', new AppReviewResource($review), 201);
                    }
                }
            } else {
                return CommonService::errorResp("event doesn't exists!", 400);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function positionDelete(string $id)
    {
        try {
            $positionData = Position::where('id', $id)->first();
            if (!empty($positionData)) {
                $eventData = Event::where('id', $positionData->event_id)->first();
                if (!empty($eventData)) {
                    if ($eventData->user->id != auth()->user()->id) {
                        return CommonService::errorResp("You cannot access this event.!", 400);
                    }
                    // Position::findOrFail($id);
                    $position = Position::where('id', $id)->delete();
                    if ($position) {
                        Day::where('position_id', $id)->delete();
                    }
                    $jobData = Job::where([['position_id', '=', $id], ['job_status', '=', 'H']])->pluck('user_id')
                        ->toArray();
                    if (!empty($jobData)) {
                        $hiredCrew = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                        // dd($hiredCrew);
                        if (!empty($hiredCrew)) {
                            Mail::to($hiredCrew)->send(new PositionCancelMail($positionData, $eventData));
                        }
                    }
                    return CommonService::successResp('position data successfully deleted ..', [], 201);
                } else {
                    return CommonService::errorResp("This position's event doesn't exists!", 400);
                }
            } else {
                return CommonService::errorResp("position doesn't exists!", 400);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }


    public function dayDelete(string $id)
    {
        try {
            $day = Day::with('position.event.user')->findOrFail($id);
            if ($day->position->event->user->id != auth()->user()->id) {
                return CommonService::errorResp("You cannot access this event.!", 400);
            }
            Day::where('id', $id)->delete();
            return CommonService::successResp('day data successfully deleted ..', [], 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function addCrewToFavourite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'is_favourite' => 'required|in:0,1'
        ], [
            'is_favourite.in' => 'The is_favourite value must be 0 or 1.'
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $favourite = CrewFavourite::where([['user_id', $data['user_id']], ['manager_id', auth()->user()->id]])->first();
            if (!empty($favourite)) {
                $favourite = CrewFavourite::where([['user_id', $data['user_id']], ['manager_id', auth()->user()->id]])->update($request->only('is_favourite'));
                return CommonService::successResp('Favourite data updated successfully ..', [], 201);
            } else {
                $favourite = CrewFavourite::create($request->only('user_id', 'is_favourite') + [
                    'manager_id' => auth()->user()->id
                ]);
                return CommonService::successResp('Favourite data add successfully ..', $favourite, 201);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getFavouriteCrew(Request $request)
    {
        try {
            $filterData = $request->all();
            $pageSize = array_key_exists('limit', $filterData) ? intval($filterData['limit']) : null;
            $favourite = CrewFavourite::where([['manager_id', auth()->user()->id], ['is_favourite', '1']])
                ->whereHas('user')->with('user')
                ->paginate(!empty($pageSize) && $pageSize > 0 ? $pageSize : config('app.pagination_per_page'));
            if ($favourite) {
                return CommonService::successResp('Favourite list get successfully ..', new CrewFavoriteCollection($favourite), 201);
            } else {
                return CommonService::errorResp('Favourite list not found ..', 400);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }
}
