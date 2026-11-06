@if(session('ok'))<div class="mdr-notice mdr-success" role="status">{{ session('ok') }}</div>
@endif

@if($errors->any())<div class="mdr-notice mdr-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>
@endforeach
</ul></div>
@endif
