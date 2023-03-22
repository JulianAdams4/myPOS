{{-- {{dd($request['data']['resume'])}} --}}
<table style="background-color: white !important;">
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td>{{$request['data']['resume']['store_name']}}</td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td>Comparativo de ventas por mesero</td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td> <strong>Periodo del</strong> {{$request['date']['from']}} <strong>al</strong> {{$request['date']['to']}}</td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td style="border: 1px solid black;">MESERO</td>
		<td style="border: 1px solid black;">VENTAS</td>
		<td style="border: 1px solid black;">PROM/CLIE</td>
		<td style="border: 1px solid black;">PROM/MESA</td>
		<td style="border: 1px solid black;"># CLIENTES</td>
		<td style="border: 1px solid black;"># MESAS</td>
		<td style="border: 1px solid black;">% VENTAS</td>
		<td style="border: 1px solid black;">PROPINAS</td>
	</tr>
	@foreach($request['data']['data'] as $data)
		<tr>
			<td style="border: 1px solid black;">{{$data['employee_name']}}</td>
			<td style="border: 1px solid black;">{{$data['total_sales']}}</td>
			<td style="border: 1px solid black;">{{$data['total_sales_avg_by_client']}}</td>
			<td style="border: 1px solid black;">{{$data['total_sales_avg_by_table']}}</td>
			<td style="border: 1px solid black;">{{$data['total_clients_attended']}}</td>
			<td style="border: 1px solid black;">{{$data['total_tables_attended']}}</td>
			<td style="border: 1px solid black;">{{$data['total_sales_percentage']}}</td>
			<td style="border: 1px solid black;">{{$data['total_tips']}}</td>
		</tr>
	@endforeach
	{{-- @foreach($request['data']['resume'] as $data) --}}
	<tr>
		<td style="border: 1px solid black;"><strong>TOTALES</strong></td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_sales_store']}}</td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_sales_avg_by_client']}}</td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_sales_avg_by_table']}}</td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_clients_attended']}}</td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_tables_attended']}}</td>
		<td style="border: 1px solid black;">0</td>
		<td style="border: 1px solid black;">{{$request['data']['resume']['sum_tips_store']}}</td>
	</tr>
	{{-- @endforeach --}}
</table>