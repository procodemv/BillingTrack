<?php

/**
 * This file is part of FusionInvoiceFOSS.
 *
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace FI\Modules\Scheduler\Controllers;

use FI\Http\Controllers\Controller;
use FI\Modules\Scheduler\Requests\ReportRequest;
use FI\Modules\Employees\Models\Employee;
use FI\Modules\Products\Models\Product;
use FI\Modules\Scheduler\Models\Schedule;
use FI\Modules\Scheduler\Models\ScheduleReminder;
use FI\Modules\Scheduler\Models\ScheduleOccurrence;
use FI\Modules\Scheduler\Models\ScheduleResource;
use FI\Modules\Scheduler\Models\Category;
use FI\Modules\Settings\Models\Setting;
use Illuminate\Support\Collection;
use Recurr;
use Recurr\Transformer;
use Recurr\Exception;
use Carbon\Carbon;
use DB;
use Auth;
use Session;
use Response;
use Illuminate\Http\Request;
use FI\Modules\CompanyProfiles\Models\CompanyProfile;
use FI\Modules\Scheduler\Requests\EventRequest;
//for coreevnts
use FI\Modules\Scheduler\Support\CalendarEventPresenter;
use FI\Modules\Quotes\Models\Quote;
use FI\Modules\Workorders\Models\Workorder;
use FI\Modules\Invoices\Models\Invoice;
use FI\Modules\Payments\Models\Payment;
use FI\Modules\Expenses\Models\Expense;
use FI\Modules\TimeTracking\Models\TimeTrackingProject;
use FI\Modules\TimeTracking\Models\TimeTrackingTask;



class SchedulerController extends Controller
{
    public function index()
    {
	    $today = new Carbon();

	    $data['monthEvent'] = Schedule::withOccurrences()->where( 'schedule_occurrences.start_date', '>=', $today->copy()->modify( '0:00 first day of this month' ) )
	                                  ->where( 'schedule_occurrences.start_date', '<=', $today->copy()->modify( '23:59:59 last day of this month' ) )
	                                  ->count();


// alternate eloquent way...
//		$data['monthEvent'] = Schedule::whereHas('occurrences',function($q) use($today){
//			$q->where( 'start_date', '>=', $today->copy()->modify( '0:00 first day of this month' ) )
//			  ->where( 'schedule_occurrences.start_date', '<=', $today->copy()->modify( '23:59:59 last day of this month' ) );
//			})->count();

	    $data['lastMonthEvent'] = Schedule::withOccurrences()->where( 'schedule_occurrences.start_date', '>=', $today->copy()->modify( '0:00 first day of last month' ) )
	                                      ->where( 'schedule_occurrences.start_date', '<=', $today->copy()->modify( '23:59:59 last day of last month' ) )
	                                      ->count();

	    $data['nextMonthEvent'] = Schedule::withOccurrences()->where( 'schedule_occurrences.start_date', '>=', $today->copy()->modify( '0:00 first day of next month' ) )
	                                      ->where( 'schedule_occurrences.start_date', '<=', $today->copy()->modify( '23:59:59 last day of next month' ) )
	                                      ->count();

	    $data['fullMonthEvent'] = Schedule::withOccurrences()->select( DB::raw( "count('id') as total, DATE_FORMAT(schedule_occurrences.start_date, '%Y%m%d') as start_date" ) )
	                                      ->where( 'schedule_occurrences.start_date', '>=', date( 'Y-m-01' ) )
	                                      ->where( 'schedule_occurrences.start_date', '<=', date( 'Y-m-t' ) )
	                                      ->groupBy( 'start_date' )
	                                      ->get();

	    $data['fullYearMonthEvent'] = Schedule::withOccurrences()->select( DB::raw( "count('id') as total, DATE_FORMAT(schedule_occurrences.start_date, '%Y%m%d') as start_date" ) )
	                                          ->where( 'schedule_occurrences.start_date', '>=', date( 'Y-01-01' ) )
	                                          ->where( 'schedule_occurrences.start_date', '<=', date( 'Y-12-31' ) )
	                                          ->groupBy('start_date')
	                                          ->get();

	    $data['reminders'] = ScheduleReminder::whereHas('schedule')->where( 'reminder_date', '>=', $today->copy()->modify( '0:00' ) )->get();

        return view('schedule.dashboard', $data);
    }

    public function calendar()
    {
        //only fetch back configured amount of days

        $data['status'] = (request('status')) ?: 'now';

        $data['events'] = Schedule::withOccurrences()->with('resources','reminders')->whereDate('start_date', '>=',
                            Carbon::now()->subDays(config('fi.schedulerPastdays')))->get();//->last();
        $data['categories'] = Category::pluck('name','id');
        $data['catbglist'] = Category::pluck('bg_color','id');
	    $data['cattxlist'] = Category::pluck('text_color','id');
        $data['companyProfiles'] = CompanyProfile::getList();

        //retrieve configured coreevents
        $coreevents = [];
        $filter = request()->filter ?: (new Setting())->coreeventsEnabled();

        $coredata = [
            //quote sent or approved,not invoiced, with client
            'quote' => Quote::where('invoice_id', '0')
                 ->where(function ($query) {$query->sentorapproved();})
                 ->with('client'),
            //workorder sent or approved,not invoiced, with client
            'workorder' => Workorder::where('invoice_id', '0')
                 ->where(function ($query) {$query->sentorapproved();})
                 ->with('client', 'workorderItems.employees'),
            'invoice' => Invoice::sent()->with('client'),
            'payment' => Payment::with(['invoice', 'paymentMethod']),
            'expense' => Expense::status('not_billed')->with(['category']),
            'project' => TimeTrackingProject::statusid('1'),
            'task'    => TimeTrackingTask::unbilled()->with(['project', 'timers']),
        ];

        foreach ($coredata as $type => $source) {
            if (!count($filter) || in_array($type, $filter)) {
                $source->where(function ($query) {
                    $start = Carbon::now()->subDays(config('fi.schedulerPastdays'));
                    $end = Carbon::now()->addCentury();//really.....
                    return $query->dateRange($start, $end);
                });

                foreach ($source->get() as $entity) {
                    $coreevents[] = (new CalendarEventPresenter())->calendarEvent($entity, $type);
                }
            }
        }

        $data['coreevents'] = $coreevents;

        return view('schedule.calendar', $data);
    }

	//event create or edit
	public function editEvent( $id = null ) {
		if ( $id ) { //if edit route called with id parameter
			$data = [
				'schedule'   => Schedule::withOccurrences()->find( $id ),
				'categories' => Category::pluck( 'name', 'id' ),
				'url'        => 'schedule\edit_event',
				'title'      => 'update_event',
				'message'    => 'event_updated'
			];

			return view('schedule.eventEdit', $data );

		} else {// no id - create new
			$schedule = new Schedule();
			$data = [
				'schedule'   => $schedule,
				'url'        => 'schedule\edit_event',
				'title'      => 'create_event',
				'message'    => 'event_created',
				'categories' => Category::pluck( 'name', 'id' )
			];
			//defaults
			$schedule['category_id'] = 3;
			$schedule['start_date'] = Date( 'Y-m-d' ) . ' 08:00';
			$schedule['end_date'] = Date( 'Y-m-d' ) . ' 16:00';

			return view('schedule.eventEdit', $data );
		}
	}

	//event store or update
	public function updateEvent( EventRequest $request ) {
		$event = ($request->id) ? Schedule::find( $request->id ) : new Schedule();

		$event->title       = $request->title;
		$event->description = $request->description;
		$event->category_id = $request->category_id;
		$event->user_id     = Auth::user()->id;

		$event->save();

		$occurrence = ($request->id) ? ScheduleOccurrence::find( $request->oid ) : new ScheduleOccurrence();

		$occurrence->schedule_id   = $event->id;
		$occurrence->start_date = $request->start_date.':00';
		$occurrence->end_date   = $request->end_date.':00';
		$occurrence->save();

		//delete existing resources for the event
		ScheduleResource::where('schedule_id', '=', $event->id)->forceDelete();

		if ( $request->category_id == 3 ) { //if client appointment
			//if ( ! empty( config( 'workorder_settings.version' ) ) ) {//check if workorder addon is installed
				$employee = Employee::where( 'short_name', '=', $request->title )->where( 'active', 1 )->first();
				if ($employee && $employee->schedule == 1) { //employee exists and is scheduleable...
					$scheduleItem = ScheduleResource::firstOrNew(['id' => $event->id]);
					$scheduleItem->id = $event->id;
					$scheduleItem->schedule_id = $event->id;
					//$scheduleItem->fid = 2;
					$scheduleItem->resource_table = 'employees';
					$scheduleItem->resource_id = $employee->id;
					$scheduleItem->value = $event->title;
					$scheduleItem->qty = 1;
					$scheduleItem->save();
				}
			//}
		}

		if ( $request->id ) {
			ScheduleReminder::where( 'schedule_id', $request->id )->forceDelete();
		}
		if ( $request->reminder_date && is_array( $request->reminder_date ) && ! empty( $request->reminder_date ) ) {

			for ( $i = 0; $i <= count( $request->reminder_date ) - 1; $i ++ ) {
				$reminder                    = new ScheduleReminder();
				$reminder->schedule_id       = $event->id;
				$reminder->reminder_date     = $request->reminder_date[ $i ].':00';
				$reminder->reminder_location = $request->reminder_location[ $i ];
				$reminder->reminder_text     = $request->reminder_text[ $i ];
				$reminder->save();
			}
		}

		//retrieve for fullcalendar render after create
		$catinfo    = Category::where( 'id', '=', $event->category_id )->first();
		$text_color = $catinfo->text_color;
		$bg_color   = $catinfo->bg_color;

		$response = [
			'type'       => 'success',
			'data'       => $event->id,
			'dataoid'    => $occurrence->oid,
			'text_color' => $text_color,
			'bg_color'   => $bg_color,
		];

		return Response::json( $response );


	}

    public function tableEvent()
    {
        //$data['events'] = Schedule::with('category')->orderBy('start_date', 'desc')->paginate(500);
        $data['events'] = Schedule::withOccurrences()->
                        with('category')->where('isRecurring', '<>', '1')->orderBy('start_date', 'desc')->get();//paginate(500);
        //$data['companyProfiles'] =  ['' => trans('fi.all_company_profiles')] + CompanyProfile::getList();

        return view('schedule.tableEvent', $data);
    }

	/**
	 * @param Request $request
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 * @throws Exception\InvalidRRule
	 */
	public function tableRecurringEvent(Request $request)
    {
	        //require_once __DIR__ . '/../vendor/autoload.php';
            $data['events'] = Schedule::where('isRecurring',1)->
            with('category')->get();//->paginate(500);

            //add human readable rule to array
            foreach ($data['events'] as $i => $event) {
                $rule = new Recurr\Rule($event->rrule, new \DateTime());
                $textTransformer = new Recurr\Transformer\TextTransformer();
                $data['events'][$i]->textTrans = $textTransformer->transform($rule);
            }

            return view('schedule.tableRecurringEvent', $data);
    }

	/**
	 * @param null $id
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 * @throws Exception\InvalidRRule
	 */
	//recurring event create or edit
	public function editRecurringEvent( $id = null ) {
		if ( $id ) { //if edit route called with id parameter

			$schedule = Schedule::withOccurrences()->find( $id );
			$rule     = Recurr\Rule::createFromString( $schedule->rrule);
			//$rule = new Recurr\Rule($schedule->rrule);
			$textTransformer = new Recurr\Transformer\TextTransformer();

			$rrule = [
				"frequency"  => $rule->getString(),
				"freqtext"   => $textTransformer->transform( $rule ),
				"freq"       => $rule->getFreqAsText(),
				"start_date" => $rule->getStartDate()->format( 'Y-m-d H:i' ),
				"end_date"   => $rule->getEndDate()->format( 'Y-m-d H:i' ),
				"until"      => ($rule->getUntil())?$rule->getUntil()->format( 'Y-m-d H:i' ):'',
				"count"      => $rule->getCount(),
				"interval"   => $rule->getInterval(),
				"wkst"       => $rule->getWeekStart(),
				"byday"      => $rule->getByDay(),
				"bysetpos"   => $rule->getBySetPosition(),
				"bymonthday" => $rule->getByMonthDay(),
				"byyearday"  => $rule->getByYearDay(),
				"byweekno"   => $rule->getByWeekNumber(),
				"bymonth"    => $rule->getByMonth(),
			];

			$data = [
				'schedule'   => $schedule,
				'categories' => Category::pluck( 'name', 'id' ),
				'url'        => 'schedule\edit_event',
				'title'      => 'update_recurring_event',
				'message'    => 'recurring_event_updated',
				'rrule'      => $rrule,
			];

			return view('schedule.recurringEventEdit', $data );

		} else {// no id - create new
			$schedule = new Schedule();
			$data     = [
				'schedule'   => $schedule,
				'rrule'      => [
					"freq"       => 'WEEKLY',
					"start_date" => Date( 'Y-m-d' ) . ' 08:00',
					"end_date"   => Date( 'Y-m-d' ) . ' 16:00',
					"wkst"       => 'MO',
				],
				'url'        => 'schedule\edit_event',
				'title'      => 'create_recurring_event',
				'message'    => 'recurring_event_created',
				'categories' => Category::pluck( 'name', 'id' )
			];
			//defaults
			$schedule['category_id'] = 3;

			return view('schedule.recurringEventEdit', $data );
		}
	}

	/**
	 * @param EventRequest $request
	 *
	 * @return \Illuminate\Http\JsonResponse
	 * @throws Exception\InvalidRRule
	 * @throws Exception\InvalidWeekday
	 */
	//recurring event store or update
	public function updateRecurringEvent( EventRequest $request ) {
		//generate rrule
		$allfields = $request->all();

		//remap start and end to RRULE types
		$allfields['DTSTART'] = $allfields['start_date'];
		$allfields['DTEND']   = $allfields['end_date'];
		unset( $allfields['start_date'] );
		unset( $allfields['end_date'] );
		$allfields = array_change_key_case( $allfields, CASE_UPPER );
		//clear all empty
		$allfields = array_filter( $allfields );

		$timezone = 'America/Phoenix';

		$rule            = Recurr\Rule::createFromArray( $allfields );
		$transformer     = new Recurr\Transformer\ArrayTransformer();
		$textTransformer = new Recurr\Transformer\TextTransformer();
		$recurrences     = $transformer->transform( $rule );

		$event = ( $request->id ) ? Schedule::find( $request->id ) : new Schedule();

		$event->title       = $request->title;
		$event->description = $request->description;
		$event->isRecurring = 1;
		$event->rrule       = $rule->getString();
		//$event->start_date  = $rule->getStartDate();
		//$event->end_date    = $rule->getEndDate();
		$event->category_id = $request->category_id;
		$event->user_id     = Auth::user()->id;

		$event->save();

		$event->occurrences()->forceDelete();
		foreach ( $recurrences as $index => $item ) {
			$occurrence             = new ScheduleOccurrence();
			$occurrence->schedule_id   = $event->id;
			$occurrence->start_date = $item->getStart();
			$occurrence->end_date   = $item->getEnd();
			$occurrence->save();
		}

		//delete existing resources for the event
		ScheduleResource::where('schedule_id', '=', $event->id)->forceDelete();

		if ( $request->category_id == 3 ) { //if client appointment
			//if ( ! empty( config( 'workorder_settings.version' ) ) ) {//check if workorder addon is installed
				$employee = Employee::where( 'short_name', '=', $request->title )->where( 'active', 1 )->first();
				if ($employee && $employee->schedule == 1) { //employee exists and is scheduleable...
					$scheduleItem = ScheduleResource::firstOrNew(['id' => $event->id]);
					$scheduleItem->id = $event->id;
					$scheduleItem->schedule_id = $event->id;
					//$scheduleItem->fid = 2;
					$scheduleItem->resource_table = 'employees';
					$scheduleItem->resource_id = $employee->id;
					$scheduleItem->value = $event->title;
					$scheduleItem->qty = 1;
					$scheduleItem->save();
				}
			//}
		}

		if ( $request->id ) {

			ScheduleReminder::where( 'schedule_id', $request->id )->forceDelete();
		}
		if ( $request->reminder_date && is_array( $request->reminder_date ) && ! empty( $request->reminder_date ) ) {

			for ( $i = 0; $i <= count( $request->reminder_date ) - 1; $i ++ ) {

				$reminder                    = new ScheduleReminder();
				$reminder->schedule_id       = $event->id;
				$reminder->reminder_date     = $request->reminder_date[ $i ].':00';
				$reminder->reminder_location = $request->reminder_location[ $i ];
				$reminder->reminder_text     = $request->reminder_text[ $i ];
				$reminder->save();
			}
		}

		//retrieve for fullcalendar render after create
		$catinfo    = Category::where( 'id', '=', $event->category_id )->first();
		$text_color = $catinfo->text_color;
		$bg_color   = $catinfo->bg_color;

		$response = [
			'type'       => 'success',
			'data'       => $event->id,
			'dataoid'    => $occurrence->oid,
			'text_color' => $text_color,
			'bg_color'   => $bg_color,
		];

		return Response::json( $response );


	}

    public function tableReport(ReportRequest $request)
    {
	    if ( $request->isMethod( 'post' ) ) {
		    $data['events'] = Schedule::withOccurrences()->where( 'start_date', '>=', $request->start )
		                              ->where( 'start_date', '<=', $request->end )
		                              ->get();
		    if ( $data['events']->isEmpty() ) {
			    Session::flash( 'error', 'No events found with specified dates' );
			    return back();
		    }

		    return view('schedule.tableEvent', $data );

	    } else {

		    return view('schedule.tableReportView' );
	    }
    }

    public function calendarReport(ReportRequest $request)
    {
	    if ( $request->isMethod( 'post' ) ) {
		    $data['events'] = Schedule::withOccurrences()->with( 'resources' )->where( 'start_date', '>=', $request->start )
		                              ->where( 'start_date', '<=', $request->end )
		                              ->get();
		    if ( $data['events']->isEmpty() ) {
			    Session::flash( 'error', 'No events found with specified dates' );
			    return back();
		    }

            $data['status'] = (request('status')) ?: 'now';
		    $data['categories'] = Category::pluck('name','id');
		    $data['catbglist'] = Category::pluck('bg_color','id');
		    $data['cattxlist'] = Category::pluck('text_color','id');
		    $data['companyProfiles'] = CompanyProfile::getList();

            //retrieve configured coreevents
            $coreevents = [];
            $filter = request()->filter ?: (new Setting())->coreeventsEnabled();

            $coredata = [
                //quote sent or approved,not invoiced, with client
                'quote' => Quote::where('invoice_id', '0')
                    ->where(function ($query) {$query->sentorapproved();})
                    ->with('client'),
                //workorder sent or approved,not invoiced, with client
                'workorder' => Workorder::where('invoice_id', '0')
                    ->where(function ($query) {$query->sentorapproved();})
                    ->with('client', 'workorderItems.employees'),
                'invoice' => Invoice::sent()->with('client'),
                'payment' => Payment::with(['invoice']),
                'expense' => Expense::status('not_billed')->with(['category']),
                'project' => TimeTrackingProject::statusid('1'),
                'task'    => TimeTrackingTask::unbilled()->with(['project', 'timers']),
            ];

            foreach ($coredata as $type => $source) {
                if (!count($filter) || in_array($type, $filter)) {
                    $source->where(function ($query) use ($request) {
                        $start = Carbon::createFromFormat('Y-m-d H:i',$request->start);
                        $end =  Carbon::createFromFormat('Y-m-d H:i',$request->end) ;
                        return $query->dateRange($start, $end);
                    });

                    foreach ($source->get() as $entity) {
                        $coreevents[] = (new CalendarEventPresenter())->calendarEvent($entity, $type);
                    }
                }
            }

            $data['coreevents'] = $coreevents;


		    return view('schedule.calendar', $data );

	    } else {
		    return view('schedule.calendarReportView' );
	    }
    }

    public function scheduledResources($date)
    {
        $scheduled_calresources = Schedule::withOccurrences()->with('resources')->whereDate('start_date','=', $date)->get();
        $scheduled_resources = Workorder::with('workorderItems')->whereDate('job_date','=', $date)->get();
        $drivers =  Employee::where('active','=','1')->where('driver','=', 1)->pluck('id','short_name')->toArray();
        //active, scheduleable employees
        $active_employees = Employee::where('active','=','1')->where('schedule', '=', '1')->pluck('short_name','id')->toArray();
        //$active_resources = Resource::where('active','=','1')->pluck('name','id')->toArray();
        $active_resources = Product::where('active','=','1')->get(['id','name','numstock'])->toArray();

		$scheduled_clients   = [];
		$scheduled_employees = [];
		$scheduled_equipment = [];

        foreach ($scheduled_calresources as $calitem) {
            foreach ($calitem->resources as $resource) {
                if ($resource->resource_table == 'employees') {
                    $scheduled_employees[$resource->resource_id] = $resource->value;//client appointments
                }
            }
        }

        foreach ($scheduled_resources as $item){
            foreach ($item->workorderItems as $resitem) {
                if ($resitem->resource_table == 'employees') {
                    $scheduled_employees[$resitem->resource_id] = $resitem->value;//employees from schedule_resources
                } else if ($resitem->resource_table == 'products') {
                    if(!isset($scheduled_equipment[$resitem->resource_id])) {
                        $scheduled_equipment[$resitem->resource_id]['id'] = $resitem->resource_id;
                        $scheduled_equipment[$resitem->resource_id]['name'] = $resitem->name;//resources from schedule_resources
                        $scheduled_equipment[$resitem->resource_id]['numstock'] = $resitem->quantity;
                    }else{
                        $scheduled_equipment[$resitem->resource_id]['numstock'] += $resitem->quantity;
                    }
                }
            }
        }

        //merge client appointments and scheduled_employees
        $scheduled_all = $scheduled_clients + $scheduled_employees;

        // build array of AVAILABLE workers
        if (isset($scheduled_all)) {
            $available_employees = array_diff_key($active_employees, $scheduled_all);
        } else {
            $available_employees = $active_employees;
        }
        //check if drivers in list and color blue
        foreach ($available_employees as $key => $value){
            if (in_array($key,$drivers)){
                //prepending __D to indicate driver - parsed in jquery to change color
                $available_employees[$key] = '___D'.$value;
            }
        }

        // build array of AVAILABLE resources
        if (isset($scheduled_equipment)) {
            //check if scheduled resource is availalble against resource numstock
            $scheduled_instock = array();
            foreach ($scheduled_equipment as $equip){
                foreach ($active_resources as $active){
                    if ($equip['id'] == $active['id']){
                        if($equip['numstock'] < $active['numstock']){
                            array_push($scheduled_instock,$equip);
                        }
                    }
                }
            }
            //remove equipment that is not in stock
            $scheduled_equipment = array_udiff($scheduled_equipment,$scheduled_instock, function ($a, $b){return $b['id'] - $a['id'] ;});
            //remove unavailable resource
            $available_resources = array_udiff($active_resources, $scheduled_equipment, function ($a, $b){return $b['id'] - $a['id'] ;});
        } else {
            $available_resources = $active_resources;
        }

        return response()->json(['success' => true, 'available_employees' => $available_employees,'available_resources' => $available_resources], 200);
    }

    //trash
    public function trashEvent($id ) {
        $event = Schedule::find( $id );
        $event->delete();

        //return 'true';
        return response()->json(['success' => trans('fi.record_successfully_trashed')], 200);
    }

    public function trashReminder( Request $request ) {
        $event = ScheduleReminder::find( $request->id );
        $event->delete();

        return back()->with('alertSuccess', trans('fi.reminder_trashed_success'));
    }

    public function bulkTrash()
    {
        foreach (Schedule::whereIn('id',request('ids'))->get() as $delschedule){

            $delschedule->delete();

        }
        return response()->json(['success' => trans('fi.record_successfully_trashed')], 200);

    }

	/**
	 * @param EventRequest $request
	 *
	 * @return \Illuminate\Http\JsonResponse
	 * @throws Exception\InvalidRRule
	 */
	public function getHuman( EventRequest $request ) {
		//get human readable rule from dialog
		//generate rrule
		$allfields = $request->all();
		$allfields = array_change_key_case( $allfields, CASE_UPPER );
		//clear all empty
		$allfields = array_filter( $allfields );

		$timezone = 'America/Phoenix';

		$rule            = Recurr\Rule::createFromArray( $allfields );
		$textTransformer = new Recurr\Transformer\TextTransformer();
		$textTrans       = $textTransformer->transform( $rule );

		$response['type']   = 'success';
		$response['result'] = $textTrans;

		return Response::json( $response );
	}

}
