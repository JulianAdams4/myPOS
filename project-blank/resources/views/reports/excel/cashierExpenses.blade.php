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
		<td>{{$request['store_name']}}</td>
		<td></td>
		<td></td>
		<td></td>
	</tr>
	<tr>
		<td></td>
		<td></td>
		<td></td>
		<td>Detalle de gastos por cajas</td>
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

	@foreach($request['data'] as $caja)
		<tr>
			<th colspan="2">
				<p>{{$caja['date_open']}} <strong>{{$caja['hour_open']}}</strong>
					@if($caja['date_close'] == "")
						→ Aún no cierra 
					@else
						→ {{$caja['date_close']}} <strong>{{$caja['hour_close']}}</strong>
					@endif
					- Encargado: {{$caja['employee_open']['name']}}
				</p>
			</th>

		</tr>
		<tr>
			<th style="border: 1px solid black; background-color: yellow;"><strong>CONCEPTO</strong></th>
			<th style="border: 1px solid black; background-color: yellow;"><strong>VALOR</strong></th>
		</tr>
	@foreach($caja['expenses'] as $expense)
		<tr>
			<td style="border: 1px solid black;">{{$expense['name']}}</td>
			<td style="border: 1px solid black;">{{ round($expense['value'] / 100, 0, PHP_ROUND_HALF_EVEN) }}</td>
		</tr>
	@endforeach
	
		<tr>
			<td style="border: 1px solid black;"></td>
			<td style="border: 1px solid black;">{{ round($caja['total_expenses'] / 100, 0, PHP_ROUND_HALF_EVEN) }}</td>
		</tr>
		<tr><td></td></tr>
		<tr><td></td></tr>
	@endforeach
</table>