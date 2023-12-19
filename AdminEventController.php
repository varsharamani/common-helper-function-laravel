<?php

namespace App\Http\Controllers\API\Admin;

use PDF;
use DateTime;
use Exception;
use DateTimeZone;
use Carbon\Carbon;
use App\Models\Day;
use App\Models\Job;
use App\Models\User;
use App\Models\Event;
use App\Models\Review;
use App\Models\Position;
use App\Models\DayDetail;
use App\Models\JobReport;
use App\Mail\EventEditMail;
use App\Mail\EventCloseMail;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Mail\EventCancelMail;
use App\Models\CrewFavourite;
use App\Models\JobPreference;
use App\Mail\EventCreatedMail;
use App\Services\CommonService;
use Illuminate\Validation\Rule;
use App\Mail\PositionCancelMail;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\DayResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\CrewNotifyAfter24Hours;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\DayCollection;
use App\Http\Resources\EventResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\UserCollection;
use App\Http\Resources\EventCollection;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\PositionResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\PositionCollection;
use App\Rules\EndDateNotBeforeArrivalDate;
use App\Rules\DayFromDateIsBetweenArrivalAndEndDate;

class AdminEventController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->all();
        $status = array_key_exists('status', $data) && !empty($data['status']) ? $data['status'] : 'All';
        $pageSize = array_key_exists('limit', $data) ? intval($data['limit']) : null;
        $sortField = array_key_exists('sort_field', $data) && !empty($data['sort_field'])  ? $data['sort_field'] : 'id';
        $sortOrder = array_key_exists('sort_order', $data) && !empty($data['sort_order']) ? $data['sort_order'] : 'desc';

        if ($status !== 'All') {
            $events = Event::where('status', $status)->whereHas('user', function ($this_query) use ($request) {
                if (!empty($request['search'])) {
                    $this_query->Where('events.name', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.overview', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.from_date', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.to_date', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('users.full_name', 'LIKE', '%' . $request['search'] . '%');
                }
            })->whereHas('positions', function ($thisPositions) use ($request) {
                $thisPositions->whereHas('days');
            })->with('user', 'positions', 'positions.days');
        } else {
            $events = Event::whereHas('user', function ($this_query) use ($request) {
                if (!empty($request['search'])) {
                    $this_query->Where('events.name', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.overview', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.from_date', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('events.to_date', 'LIKE', '%' . $request['search'] . '%');
                    $this_query->orWhere('users.full_name', 'LIKE', '%' . $request['search'] . '%');
                }
            })->whereHas('positions', function ($thisPositions) use ($request) {
                $thisPositions->whereHas('days');
            })->with('user', 'positions', 'positions.days');
        }

        $events = $events->orderBy($sortField, $sortOrder)
            ->paginate(!empty($pageSize) && $pageSize > 0 ? $pageSize : config('app.pagination_per_page'));

        $events->open_count = Event::where('status', 'O')->get()->count();
        $events->close_count = Event::where('status', 'CL')->get()->count();
        $events->cancel_count = Event::where('status', 'CA')->get()->count();

        return CommonService::successResp('', new EventCollection($events), 201);
    }

    public function store(Request $request)
    {
        $currentDateTime = Carbon::now();
        $dateTimeAfter30Minutes = $currentDateTime->addMinutes(30);
        $dateTimeAfter30Minutes->second(0);
        $futureDate = date('Y-m-d H:i:s', strtotime($dateTimeAfter30Minutes));

        $validator = Validator::make($request->all(), [
            'manager_id' => 'required',
            'name' => 'required|string|min:1|max:80',
            'overview' => 'required|string|min:1|max:400',
            'location' => 'required|min:1|max:255',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            'from_date' => 'nullable|date_format:Y-m-d H:i:s|after:' . $futureDate,
            'to_date' => 'nullable|date_format:Y-m-d H:i:s|after:' . $request['from_date'],
            'csm_id' => 'nullable',
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
            'manager_id.required' => 'The event manager field is required.',
            'overview.required' => 'The event description field is required',
            'from_date.date_format' => 'The event start date field must match the format Y-m-d H:i:s.',
            'to_date.date_format' => 'The event end date field must match the format Y-m-d H:i:s.',
            'from_date.after' => 'Sorry, you cannot create a new event within next 30 minutes of the current time. Please try again.' . $futureDate,
            'to_date.after' => 'The event end date field must be after event start date.',
            'positions.*.name' => 'The position name field is required',
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

            $event = Event::create($request->only('name', 'overview', 'location', 'csm_id') + [
                'image' => $image,
                'user_id' =>  $request->input('manager_id'),
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
                        // 'notes' => $data['positions'][$i]['notes'],
                        // 'arrival_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['arrival_date'])),
                        // 'end_date' => date('Y-m-d H:i:s', strtotime($data['positions'][$i]['end_date'])),
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

                                $eventData = Event::where('id', '!=', $event->id)->where('user_id', $request->input('manager_id'))->where('status', 'O')->where('location', $data['location'])
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
            $managerData = User::where('id', $request->manager_id)->first();
            Mail::send('emails.new_event_billing', compact('data', 'eventData'), function ($message) use ($data, $pdf, $managerData) {
                $message->to($managerData->email)
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
            dd($e);
            echo $e->getMessage();
            DB::rollBack();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function show(string $id)
    {
        try {
            $event = Event::where([['id', $id]])->with(['positions' => function ($thisPositions) {
                $thisPositions->with('days');
            }])->first();

            if ($event) {
                if ($event->positions) {
                    foreach ($event->positions as $position) {
                        if ($position->days) {
                            foreach ($position->days as $day) {
                                $day->hire_count = Job::where([['event_id', $id], ['position_id', $position->id], ['day_id', $day->id], ['job_status', 'H']])->get()->count();
                            }
                        }
                    }
                }
            }

            return CommonService::successResp('', new EventResource($event), 201);
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $events = Event::findOrFail($id);

            Notification::where('event_id', $id)->delete();
            $data = $events->delete();
            $posData = Position::where('event_id', $id)->get();
            if ($posData) {
                foreach ($posData as $posVal) {
                    $dayData = Day::where('position_id', $posVal->id)->get();
                    if ($dayData) {
                        foreach ($dayData as $dayVal) {
                            $dayDetailData = DayDetail::where('day_id', $dayVal->id)->get();
                            if ($dayDetailData) {
                                $dayDetailData->each->delete();
                            }
                            $jobData = Job::where('day_id', $dayVal->id)->get();
                            if (!empty($jobData)) {
                                $jobData->each->delete();
                            }
                        }
                        $dayData->each->delete();
                    }
                }
                $posData->each->delete();
            }

            return CommonService::successResp('Event data successfully deleted', [], 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function eventCancel(string $id)
    {
        try {
            $eventData = Event::where('id', '=', $id)->first();
            if ($eventData) {
                $event = Event::where('id', '=', $id)->update(array('status' => 'CA'));
                $jobData = Job::where([['event_id', '=', $id], ['job_status', '=', 'H']])->pluck('user_id')
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
                                'message' => "The event #$eventName you applied to has been canceled.  We apologize for any inconvenience. Stay tuned for future work opportunities.",
                                'type' => 'event_canceled',
                                'image' => $eventData->image,
                                'event_id' => $eventData->id,
                                'reference_id' => $eventData->user_id,
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
                return CommonService::successResp('Event successfully canceled', [], 201);
            } else {
                return CommonService::errorResp("event doesn't exists!", 400);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function eventClose(string $id)
    {
        try {
            $eventData = Event::where('id', '=', $id)->first();
            if ($eventData) {
                $event = Event::where('id', '=', $id)->update(array('status' => 'CL'));
                $jobData = Job::where('event_id', $id)->whereIn('job_status', ['H', 'COM'])->pluck('user_id')
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
                                'reference_id' => $eventData->user_id,
                                'reference_type' => 'manager',
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ];
                            array_push($notificationData, $data);
                        }
                        Notification::insert($notificationData);
                    }
                }
                return CommonService::successResp('Event successfully closed', [], 201);
            } else {
                return CommonService::errorResp("Event doesn't exists!", 400);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getPositionsByEvent(Request $request, string $id)
    {
        $data = $request->all();
        $pageSize = array_key_exists('limit', $data) ? intval($data['limit']) : null;
        $sortField = array_key_exists('sort_field', $data) && !empty($data['sort_field'])  ? $data['sort_field'] : 'id';
        $sortOrder = array_key_exists('sort_order', $data) && !empty($data['sort_order']) ? $data['sort_order'] : 'desc';
        try {
            $positions = Position::where([['event_id', $id]]);
            if (!empty($request['search'])) { // empty function checks 0,1,true, false,'',null;
                $positions = $positions->Where(function ($this_query) use ($request) {
                    $this_query->Where('name', 'LIKE', '%' . $request['search'] . '%')
                        ->orWhere('notes', 'LIKE', '%' . $request['search'] . '%')
                        ->orWhere('job_instructions', 'LIKE', '%' . $request['search'] . '%');
                });
            }
            $positions = $positions->orderBy($sortField, $sortOrder)
                ->paginate(!empty($pageSize) && $pageSize > 0 ? $pageSize : config('app.pagination_per_page'));

            return CommonService::successResp('', new PositionCollection($positions), 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getDaysByPosition(Request $request, string $id)
    {
        $data = $request->all();
        $pageSize = array_key_exists('limit', $data) ? intval($data['limit']) : null;
        $sortField = array_key_exists('sort_field', $data) && !empty($data['sort_field'])  ? $data['sort_field'] : 'id';
        $sortOrder = array_key_exists('sort_order', $data) && !empty($data['sort_order']) ? $data['sort_order'] : 'desc';
        try {
            $days = Day::where([['position_id', $id]])->orderBy($sortField, $sortOrder)
                ->paginate(!empty($pageSize) && $pageSize > 0 ? $pageSize : config('app.pagination_per_page'));
            if (!empty($days)) {
                $posData = Position::where('id', $id)->first();
                for ($i = 0; $i < count($days); $i++) {
                    $days[$i]->event_id = $posData->event_id;
                    $days[$i]->hire_count = Job::where([['event_id', $posData->event_id], ['position_id', $posData->id], ['day_id', $days[$i]->id], ['job_status', 'H']])->get()->count();
                }
            }
            return CommonService::successResp('', new DayCollection($days), 201);
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function showPosition(string $id)
    {
        try {
            $position = Position::where('id', $id)->first();
            return CommonService::successResp('', new PositionResource($position), 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function showDay(string $id)
    {
        try {
            $day = Day::where('id', $id)->first();
            if (!empty($day)) {
                $posData = Position::where('id', $day->position_id)->first();
                $day->event_id = $posData->event_id;
            }
            return CommonService::successResp('', new DayResource($day), 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
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
                return CommonService::errorResp('review data updated successfully', 400);
            } else {
                $review = Review::create($request->only('user_id', 'manager_id', 'event_id', 'position_id', 'day_id', 'rating', 'review'));
                return CommonService::successResp('Review data add successfully', new ReviewResource($review), 201);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function addPosition(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required',
            'name' => 'sometimes|required|string|min:1|max:255',
            'notes' => 'sometimes|nullable|string|min:1|max:255',
            'arrival_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'job_instructions' => 'sometimes|nullable|string|max:400',
        ], [
            'arrival_date.date_format' => 'The arrival date field must match the format Y-m-d H:i:s.',
            'end_date.date_format' => 'The end date field must match the format Y-m-d H:i:s.',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $position = Position::create([
                'event_id' => $data['event_id'],
                'name' => $data['name'],
                'notes' => $data['notes'],
                'arrival_date' => date('Y-m-d H:i:s', strtotime($data['arrival_date'])),
                'end_date' => date('Y-m-d H:i:s', strtotime($data['end_date'])),
                'job_instructions' => $data['job_instructions']
            ]);
            return CommonService::successResp('Position data add successfully', new PositionResource($position), 201);
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function addDay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'position_id' => 'required',
            'from_date' => 'sometimes|required|date_format:Y-m-d',
            'to_date' => 'sometimes|required|date_format:Y-m-d',
            'quantity' => 'sometimes|required|regex:/^[0-9]+$/',
            'hours_per_one' => ['sometimes', 'nullable', 'regex:/^-?(?:\d+|\d*\.\d+)$/'],
            'hourly_rate' => 'sometimes|nullable|regex:/^[0-9]+$/',
            'from_time' => 'required|date_format:H:i:s',
            'to_time' => 'required|date_format:H:i:s',
        ], [
            'from_date.date_format' => 'The from date field must match the format Y-m-d',
            'to_date.date_format' => 'The to date field must match the format Y-m-d',
            'quantity.regex' => 'The quantity field is must be number',
            'hours_per_one.regex' => 'The hours per one field is must be a valid format',
            'hourly_rate.regex' => 'The hourly rate field is must be number',
            'from_time.date_format' => 'The from time field must match the format H:i:s',
            'to_time.date_format' => 'The to time field must match the format H:i:s',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {

            $dayData =  $data;
            $date1 = Carbon::createFromFormat('Y-m-d', $data['from_date']);
            $date2 = Carbon::createFromFormat('Y-m-d', $data['to_date']);

            $fromTime = Carbon::parse($data['from_time']);
            $toTime = Carbon::parse($data['to_time']);

            $fromHour = intval(date('H', strtotime($fromTime)));
            $fromMinute = intval(date('i', strtotime($fromTime)));

            $toHour = intval(date('H', strtotime($toTime)));
            $toMinute = intval(date('i', strtotime($toTime)));

            $carbonFromDate = Carbon::parse($date1);
            $carbonToDate = Carbon::parse($date2);

            $carbonToDateInclusive = $carbonToDate->addDay();

            $totalDays = $carbonFromDate->diffInDays($carbonToDateInclusive);


            $events = Event::whereHas('positions', function ($wherePositions) use ($data) {
                $wherePositions->where('id', $data['position_id']);
                $wherePositions->with('days');
            })->first();

            $dayData = $data;
            $eventData = Event::where('id', '!=', $events['id'])->where('user_id',   $events['user_id'])->where('status', 'O')->where('location', $events['location'])
                ->whereHas('positions.days.day_details', function ($thisWherePositions) use ($dayData, $totalDays) {
                    $thisWherePositions->checkTime($dayData, $totalDays);
                })->first();
            // if (!empty($eventData)) {
            //     return CommonService::errorResp('An event already exists at this time and place. Please choose a different place.', 400);
            // } else {

            $day = Day::create([
                'position_id' => $data['position_id'],
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'quantity' => $data['quantity'],
                'hours_per_one' => $data['hours_per_one'],
                'hourly_rate' => $data['hourly_rate'],
                'from_time' => date('H:i:s', strtotime($data['from_time'])),
                'to_time' => date('H:i:s', strtotime($data['to_time'])),
            ]);

            if ($totalDays > 0) {
                for ($k = 0; $k < $totalDays; $k++) {
                    if ($fromHour < $toHour || ($fromHour === $toHour && $fromMinute < $toMinute)) {
                        $from_date = clone $date1;
                        $from_date->addDays($k);

                        DayDetail::create([
                            'day_id' => $day->id,
                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['from_time'])),
                            'to_date' => date('Y-m-d', strtotime($from_date))  . ' ' . date('H:i:s', strtotime($data['to_time'])),
                        ]);
                    } else {
                        $from_date = clone $date1;
                        $from_date->addDays($k);
                        $to_date = clone $date1;
                        $to_date->addDays($k + 1);

                        DayDetail::create([
                            'day_id' => $day->id,
                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['from_time'])),
                            'to_date' => date('Y-m-d', strtotime($to_date)) . ' ' . date('H:i:s', strtotime($data['to_time'])),
                        ]);
                    }
                }
            }

            return CommonService::successResp('Day data add successfully', new DayResource($day), 201);
            // }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function editPosition(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|min:1|max:255',
            'notes' => 'sometimes|nullable|string|min:1|max:255',
            'arrival_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'job_instructions' => 'sometimes|nullable|string|max:400',
        ], [
            'arrival_date.date_format' => 'The arrival date field must match the format Y-m-d H:i:s.',
            'end_date.date_format' => 'The end date field must match the format Y-m-d H:i:s.',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $oldPosition = Position::where('id', $id)->first();
            if (!empty($oldPosition)) {
                $eventData = Event::where('id', $oldPosition->event_id)->first();;
                if (!empty($eventData)) {
                    $position = Position::where('id', $id)->update([
                        'name' => $data['name'],
                        'notes' => $data['notes'],
                        'arrival_date' => date('Y-m-d H:i:s', strtotime($data['arrival_date'])),
                        'end_date' => date('Y-m-d H:i:s', strtotime($data['end_date'])),
                        'job_instructions' => $data['job_instructions']
                    ]);
                    $updatedPosition = Position::where('id', $id)->first();
                    if ($oldPosition->name !== $updatedPosition->name || $oldPosition->arrival_date !== $updatedPosition->arrival_date || $oldPosition->end_date !== $updatedPosition->end_date || $oldPosition->job_instructions !== $updatedPosition->job_instructions) {
                        $jobData = Job::where([['event_id', '=', $oldPosition->event_id], ['position_id', '=', $id], ['job_status', '=', 'H']])->pluck('user_id')
                            ->toArray();
                        if (!empty($jobData)) {
                            $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                            if (!empty($hiredCrewEmailNotify)) {
                                Mail::to($hiredCrewEmailNotify)->send(new EventEditMail($eventData));
                            }
                            $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                            if (!empty($hiredCrewPushNotify)) {
                                crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_updated'), 'Event Updated', "An update has been made the event $eventData->name you're participating in. Please log in to the app to view the latest changes and instructions.", '', 'event_updated');
                            }
                            $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'],  ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
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
                                        'reference_id' => $eventData->user_id,
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
                    return CommonService::successResp('Position data updated successfully', [], 201);
                } else {
                    return CommonService::errorResp("event doesn't exists!", 400);
                }
            } else {
                return CommonService::errorResp("Position doesn't exists!", 400);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function editDay(Request $request, string $id)
    {

        $validator = Validator::make($request->all(), [
            'from_date' => 'sometimes|required|date_format:Y-m-d',
            'to_date' => 'sometimes|required|date_format:Y-m-d',
            'quantity' => 'sometimes|required|regex:/^[0-9]+$/',
            'hours_per_one' => ['sometimes', 'nullable', 'regex:/^-?(?:\d+|\d*\.\d+)$/'],
            'hourly_rate' => 'sometimes|nullable|regex:/^[0-9]+$/',
            'from_time' => 'required|date_format:H:i:s',
            'to_time' => 'required|date_format:H:i:s',
        ], [
            'from_date.date_format' => 'The from date field must match the format Y-m-d',
            'to_date.date_format' => 'The to date field must match the format Y-m-d',
            'quantity.regex' => 'The quantity field is must be number',
            'hours_per_one.regex' => 'The hours per one field is must be a valid format',
            'hourly_rate.regex' => 'The hourly rate field is must be number',
            'from_time.date_format' => 'The from time field must match the format H:i:s.',
            'to_time.date_format' => 'The to time field must match the format H:i:s.',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $oldDay = Day::where('id', $id)->first();
            if (!empty($oldDay)) {
                $oldPosition = Position::where('id', $oldDay->position_id)->first();
                if (!empty($oldPosition)) {
                    $oldEvent = Event::where('id', $oldPosition->event_id)->first();
                    if (!empty($oldEvent)) {
                        $events = Event::whereHas('positions', function ($wherePositions) use ($id) {
                            $wherePositions->whereHas('days', function ($whereDays) use ($id) {
                                $whereDays->where('id', $id);
                            });
                        })->first();

                        $dayData = $data;
                        $date1 = Carbon::createFromFormat('Y-m-d', $dayData['from_date']);
                        $date2 = Carbon::createFromFormat('Y-m-d', $dayData['to_date']);

                        $fromTime = Carbon::parse($dayData['from_time']);
                        $toTime = Carbon::parse($dayData['to_time']);

                        $fromHour = intval(date('H', strtotime($fromTime)));
                        $fromMinute = intval(date('i', strtotime($fromTime)));

                        $toHour = intval(date('H', strtotime($toTime)));
                        $toMinute = intval(date('i', strtotime($toTime)));

                        $carbonFromDate = Carbon::parse($date1);
                        $carbonToDate = Carbon::parse($date2);

                        $carbonToDateInclusive = $carbonToDate->addDay();

                        $totalDays = $carbonFromDate->diffInDays($carbonToDateInclusive);


                        $eventData = Event::where('id', '!=', $events['id'])->where('user_id',   $events['user_id'])->where('status', 'O')->where('location', $events['location'])
                            ->whereHas('positions.days.day_details', function ($thisWhereDays) use ($dayData, $totalDays) {
                                // $thisWherePositions->whereHas('days', function ($thisWhereDays) use ($dayData) {
                                $thisWhereDays->checkTime($dayData, $totalDays);
                                // });
                            })->first();
                        $eventData = array();
                        // if (!empty($eventData)) {
                        //     return CommonService::errorResp('An event already exists at this time and place. Please choose a different place.', 400);
                        // } else {

                        $dayData = Day::where('id', $id)->first();
                        $hiredData = Job::where([['day_id', $id], ['job_status', 'H']])->get();

                        if (!$hiredData->isEmpty()) {
                            if (
                                $dayData->from_date !== $data['from_date']
                                || $dayData->to_date !== $data['to_date']
                                || $dayData->hourly_rate !== $data['hourly_rate']
                                || $dayData->from_time !== date('H:i:s', strtotime($data['from_time']))
                                || $dayData->to_time !== date('H:i:s', strtotime($data['to_time']))
                            ) {
                                return CommonService::errorResp("event data doesn't edit because user already hired for this event .", 400);
                            }
                        }


                        Day::where('id', $id)->update([
                            'from_date' => $data['from_date'],
                            'to_date' => $data['to_date'],
                            'quantity' => $data['quantity'],
                            'hourly_rate' => $data['hourly_rate'],
                            'hours_per_one' => $data['hours_per_one'],
                            'from_time' => date('H:i:s', strtotime($data['from_time'])),
                            'to_time' => date('H:i:s', strtotime($data['to_time'])),
                        ]);
                        $updatedDay = Day::where('id', $id)->first();

                        if ($oldDay->from_date !== $updatedDay->from_date || $oldDay->to_date !== $updatedDay->to_date || $oldDay->hourly_rate !== $updatedDay->hourly_rate || $oldDay->from_time !== $updatedDay->from_time || $oldDay->to_time !== $updatedDay->to_time) {
                            DayDetail::where('day_id', $id)->delete();

                            if ($totalDays > 0) {
                                for ($k = 0; $k < $totalDays; $k++) {
                                    if ($fromHour < $toHour || ($fromHour === $toHour && $fromMinute < $toMinute)) {
                                        $from_date = clone $date1;
                                        $from_date->addDays($k);

                                        DayDetail::create([
                                            'day_id' => $id,
                                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['from_time'])),
                                            'to_date' => date('Y-m-d', strtotime($from_date))  . ' ' . date('H:i:s', strtotime($data['to_time'])),
                                        ]);
                                    } else {
                                        $from_date = clone $date1;
                                        $from_date->addDays($k);
                                        $to_date = clone $date1;
                                        $to_date->addDays($k + 1);

                                        DayDetail::create([
                                            'day_id' => $id,
                                            'from_date' => date('Y-m-d', strtotime($from_date)) . ' ' . date('H:i:s', strtotime($data['from_time'])),
                                            'to_date' => date('Y-m-d', strtotime($to_date)) . ' ' . date('H:i:s', strtotime($data['to_time'])),
                                        ]);
                                    }
                                }
                            }

                            $jobData = Job::where([['event_id', '=', $oldEvent->id], ['position_id', '=', $oldDay->position_id], ['day_id', '=', $updatedDay->id], ['job_status', '=', 'H']])->pluck('user_id')
                                ->toArray();
                            if (!empty($jobData)) {
                                $hiredCrewEmailNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['email_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('email')->toArray();
                                if (!empty($hiredCrewEmailNotify)) {
                                    Mail::to($hiredCrewEmailNotify)->send(new EventEditMail($oldEvent));
                                }
                                $hiredCrewPushNotify = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['push_notification', '=', 1], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                if (!empty($hiredCrewPushNotify)) {
                                    crewEventNotification($hiredCrewPushNotify, getNotificationCodes('event_updated'), 'Event Updated', "An update has been made the event $oldEvent->name you're participating in. Please log in to the app to view the latest changes and instructions.", '', 'event_updated');
                                }
                                $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'], ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
                                if (!empty($hiredCrews)) {

                                    $notificationData = [];
                                    $eventName = str_replace(' ', ' #', $oldEvent->name);
                                    foreach ($hiredCrews as $hireCrew) {
                                        $data = [
                                            'user_id' => $hireCrew,
                                            'title' => "Event Updated",
                                            'message' => "An update has been made the event #$eventName you're participating in. Please log in to the app to view the latest changes and instructions.",
                                            'type' => 'event_updated',
                                            'image' => $oldEvent->image,
                                            'event_id' => $oldEvent->id,
                                            'reference_id' => $oldEvent->user_id,
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
                        return CommonService::successResp('Day data updated successfully', [], 201);
                        // }
                    } else {
                        return CommonService::errorResp("event doesn't exists", 400);
                    }
                } else {
                    return CommonService::errorResp("position doesn't exists", 400);
                }
            } else {
                return CommonService::errorResp("day doesn't exists", 400);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function editEvent(Request $request, string $id)
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
            'from_date' => 'nullable|date_format:Y-m-d H:i:s|after:' . $futureDate,
            'to_date' => 'nullable|date_format:Y-m-d H:i:s',
            'status' => 'required',
            'csm_id' => 'nullable'
        ], [
            'from_date.after' => 'Sorry, you cannot create a new event within next 30 minutes of the current time. Please try again.',
            'overview.required' => 'The event instruction field is required.',
            'from_date.date_format' => 'The from date field must match the format Y-m-d H:i:s.',
            'to_date' => 'The to date field must match the format Y-m-d H:i:s.',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            $eventData = Event::where('id', $id)->first();
            if (!empty($eventData)) {
                $image = $eventData->image;
                if ($request->file('image')) {
                    $image = Storage::disk('spaces')->put(config('app.image_path'), $request->file('image'), 'private');
                }
                $event = Event::where('id', $id)->update($request->only('name', 'overview', 'location', 'status', 'csm_id') + [
                    'image' => $image,
                    'from_date' => date('Y-m-d H:i:s', strtotime($data['from_date'])),
                    'to_date' => date('Y-m-d H:i:s', strtotime($data['to_date']))
                ]);

                $updatedEvent = Event::where('id', $id)->first();
                if ($eventData->location !== $updatedEvent->location || $eventData->from_date !== $updatedEvent->from_date || $eventData->to_date !== $updatedEvent->to_date) {
                    $jobData = Job::where([['event_id', '=', $id], ['job_status', '=', 'H']])->pluck('user_id')
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
                        $hiredCrews = User::whereIn('id', $jobData)->where([['role', '=', 'C'],  ['status', '=', 'A']])->distinct()->pluck('id')->toArray();
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
                                    'reference_id' => $eventData->user_id,
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
                return CommonService::successResp('event data updated successfully', [], 201);
            } else {
                return CommonService::errorResp("event's doesn't exists!", 500);
            }
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function deletePosition(string $id)
    {
        try {
            $positionData = Position::where('id', $id)->first();
            if (!empty($positionData)) {
                $eventData = Event::where('id', $positionData->event_id)->first();
                if (!empty($eventData)) {
                    Position::findOrFail($id);
                    $position = Position::where('id', $id)->delete();
                    $jobData = Job::where([['position_id', '=', $id], ['job_status', '=', 'H']])->pluck('user_id')
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
                            foreach ($hiredCrews as $hireCrew) {
                                $data = [
                                    'user_id' => $hireCrew,
                                    'title' => "Position Cancellation Notice",
                                    'message' => "The position #$positionData->name you applied to has been canceled. We apologize for any inconvenience. Stay tuned for future work opportunities.",
                                    'type' => 'position_canceled',
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
                    Job::where('position_id', $id)->delete();
                    if ($position) {
                        $dayData = Day::where('position_id', $id)->get();
                        if (!empty($dayData)) {
                            foreach ($dayData as $day) {
                                DayDetail::where('day_id', $day->id)->delete();
                            }
                        }
                        Day::where('position_id', $id)->delete();
                    }
                    return CommonService::successResp('position data successfully deleted ..', [], 201);
                } else {
                    return CommonService::errorResp("This position's event doesn't exists!", 400);
                }
            } else {
                return CommonService::errorResp("position doesn't exists!", 400);
            }
        } catch (Exception $e) {
            echo $e->getmessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }


    public function deleteDay(string $id)
    {
        try {
            Day::findOrFail($id);
            DayDetail::where('day_id', $id)->delete();
            Day::where('id', $id)->delete();
            Job::where('day_id', $id)->delete();
            return CommonService::successResp('day data successfully deleted ..', [], 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getAvailableCrew(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required',
            'position_id' => 'required',
            'day_id' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        $eventData = Event::where('id', $data['event_id'])->first();

        $available_crews = array();
        if (!empty($eventData)) {
            $posData = Position::where('event_id', $data['event_id'])->first();
            if ($posData) {
                $dayVal = Day::where([['position_id', $data['position_id']], ['id', $data['day_id']]])->first();
                if ($dayVal) {
                    $jobPrefData =  JobPreference::where('status', 'A')->Where('name', 'LIKE', '%' . $posData->name . '%')->first();
                    if (!empty($jobPrefData)) {
                        $users = User::where([['role', '=', 'C'], ['status', '=', 'A']])->whereRaw('FIND_IN_SET("' . $jobPrefData->id . '",job_preference)')->get();
                        if (!empty($users)) {

                            foreach ($users as $user) {
                                $userJob = Job::where([['user_id', $user->id], ['day_id', '!=', $data['day_id']], ['job_status', 'H']])->get();
                                $flag = 0;

                                $userJobData = Job::where([['user_id', $user->id], ['day_id', $data['day_id']], ['job_status', 'H']])->first();
                                if (!empty($userJobData)) {
                                    $flag = 1;
                                }
                                if (!empty($userJob)) {
                                    foreach ($userJob as $job) {
                                        $jobDayData = DayDetail::where('day_id', $job->day_id)->where(function ($query) use ($dayVal) {
                                            $query->checkEventTime($dayVal)->first();
                                        })->first();
                                        if (!empty($jobDayData)) {
                                            $flag = 1;
                                        }
                                    }
                                }

                                if ($flag == 0) {
                                    array_push($available_crews, $user);
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($available_crews) {
            return CommonService::successResp('', UserResource::collection($available_crews), 201);
        } else {
            return CommonService::successResp('', [], 201);
        }
    }
}
