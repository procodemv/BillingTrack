<table class="table table-hover" style="height: 100%;">

    <thead>
    <tr>
        <th><div class="btn-group"><input type="checkbox" id="bulk-select-all"></div></th>
        <th class="hidden-sm hidden-xs">{{ trans('fi.status') }}</th>
        <th>{{ trans('fi.quote') }}</th>
        <th class="hidden-xs">{{ trans('fi.date') }}</th>
        <th class="hidden-sm hidden-xs">{{ trans('fi.expires') }}</th>
        <th>{{ trans('fi.client') }}</th>
        <th class="hidden-sm hidden-xs">{{ trans('fi.summary') }}</th>
        <th style="text-align: right; padding-right: 25px;">{{ trans('fi.total') }}</th>
        <th>{{ trans('fi.invoiced') }}</th>
        <th>{{ trans('fi.options') }}</th>
    </tr>
    </thead>

    <tbody>
    @foreach ($quotes as $quote)
        <tr>
            <td><input type="checkbox" class="bulk-record" data-id="{{ $quote->id }}"></td>
            <td class="hidden-sm hidden-xs">
                <span class="label label-{{ $statuses[$quote->quote_status_id] }}">{{ trans('fi.' . $statuses[$quote->quote_status_id]) }}</span>
                @if ($quote->viewed)
                    <span class="label label-success">{{ trans('fi.viewed') }}</span>
                @else
                    <span class="label label-default">{{ trans('fi.not_viewed') }}</span>
                @endif
            </td>
            <td><a href="{{ route('quotes.edit', [$quote->id]) }}"
                   title="{{ trans('fi.edit') }}">{{ $quote->number }}</a></td>
            <td class="hidden-xs">{{ $quote->formatted_quote_date }}</td>
            <td class="hidden-sm hidden-xs">{{ $quote->formatted_expires_at }}</td>
            <td><a href="{{ route('clients.show', [$quote->client->id]) }}"
                   title="{{ trans('fi.view_client') }}">{{ $quote->client->unique_name }}</a></td>
            <td class="hidden-sm hidden-xs">{{ $quote->summary }}</td>
            <td style="text-align: right; padding-right: 25px;">{{ $quote->amount->formatted_total }}</td>
            <td class="hidden-xs">
                @if ($quote->invoice)
                    <a href="{{ route('invoices.edit', [$quote->invoice_id]) }}">{{ trans('fi.invoice') }}</a>
                @elseif ($quote->workorder)
                    <a href="{{ route('workorders.edit', [$quote->workorder_id]) }}">{{ trans('fi.workorder') }}</a>
                @else
                    {{ trans('fi.no') }}
                @endif
            </td>
            <td>
                <div class="btn-group">
                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                        {{ trans('fi.options') }} <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li><a href="{{ route('quotes.edit', [$quote->id]) }}"><i
                                    class="fa fa-edit"></i> {{ trans('fi.edit') }}</a></li>
                        <li><a href="{{ route('quotes.pdf', [$quote->id]) }}" target="_blank" id="btn-pdf-quote"><i
                                    class="fa fa-print"></i> {{ trans('fi.pdf') }}</a></li>
                        <li><a href="javascript:void(0)" class="email-quote" data-quote-id="{{ $quote->id }}"
                               data-redirect-to="{{ request()->fullUrl() }}"><i
                                    class="fa fa-envelope"></i> {{ trans('fi.email') }}</a></li>
                        <li><a href="{{ route('clientCenter.public.quote.show', [$quote->url_key]) }}" target="_blank"
                               id="btn-public-quote"><i class="fa fa-globe"></i> {{ trans('fi.public') }}</a></li>
                        <li><a href="#"
                               onclick="swalConfirm('{{ trans('fi.trash_record_warning') }}','{{ route('quotes.delete', [$quote->id]) }}');"><i
                                    class="fa fa-trash-o"></i> {{ trans('fi.trash') }}</a></li>
                    </ul>
                </div>
            </td>
        </tr>
    @endforeach
    </tbody>

</table>