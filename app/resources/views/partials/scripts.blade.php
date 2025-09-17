<script src="{{ asset('js/jquery.min.js') }}"></script>
<script src="{{ asset('js/popper.min.js') }}"></script>
<script src="{{ asset('js/moment.min.js') }}"></script>
<script src="{{ asset('js/bootstrap.min.js') }}"></script>
<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/simplebar.min.js') }}"></script>
<script src="{{ asset('js/jquery.stickOnScroll.js') }}"></script>
<script src="{{ asset('js/tinycolor-min.js') }}"></script>
<script src="{{ asset('js/config.js') }}"></script>
<script src="{{ asset('js/apps.js') }}"></script>
<script src="{{ asset('js/jquery.blockUI.min.js') }}"></script>
<script src="{{ asset('js/axios.min.js') }}"></script>
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('js/toastr.min.js') }}"></script>
<script>
  toastr.options = {
    "closeButton": true,
    "progressBar": true,
    "newestOnTop": true,
    "preventDuplicates": true,
    "timeOut": 4000,
    "positionClass": "toast-top-center"
  };
</script>
<script>
  @if (session('success'))
    toastr.success(@json(session('success')));
  @endif

  @if (session('error'))
    toastr.error(@json(session('error')));
  @endif

  @if (session('warning'))
    toastr.warning(@json(session('warning')));
  @endif

  @if (session('info'))
    toastr.info(@json(session('info')));
  @endif

  @if ($errors->any())
    @foreach ($errors->all() as $error)
      toastr.error(@json($error));
    @endforeach
  @endif
</script>
@yield('extra-js')