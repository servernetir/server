<script src="{{ asset('js/jquery.min.js') }}"></script>
<script src="{{ asset('js/bootstrap.min.js') }}"></script>
<script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('js/toastr.min.js') }}"></script>
<script>
  window.addEventListener('DOMContentLoaded', () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      if (csrf && window.jQuery) {$.ajaxSetup({headers: {'X-CSRF-TOKEN': csrf}});}
      toastr.options = {
          closeButton: true,
          progressBar: true,
          newestOnTop: true,
          preventDuplicates: true,
          timeOut: 4000,
          positionClass: "toast-top-center"
      };
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
      document.querySelectorAll('[data-confirm]').forEach(el => {
          el.addEventListener('click', (e) => {
              const msg = el.getAttribute('data-confirm') || 'Are you sure?';
              const title = el.getAttribute('data-confirm-title') || 'Please confirm';
              const submit = () => {if (el.tagName === 'A') {window.location.href = el.href;} else {el.closest('form')?.submit();}};
              e.preventDefault();
              Swal.fire({
                  title,
                  text: msg,
                  icon: 'warning',
                  showCancelButton: true,
                  confirmButtonText: 'Yes',
                  cancelButtonText: 'Cancel',
                  background: getComputedStyle(document.documentElement).getPropertyValue('--surface').trim() || '#11161f',
                  color: getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#e7ecf3',
                  customClass: {popup: 'panel'}
              }).then(res => {if (res.isConfirmed) submit();});
          });
      });
      const sidebar = document.getElementById('appSidebar');
      const openBtn = document.getElementById('sidebarOpenBtn');
      const overlayId = 'sidebarOverlay';
      function addOverlay() {
          if (document.getElementById(overlayId)) return;
          const ov = document.createElement('div');
          ov.id = overlayId;
          ov.style.position = 'fixed';
          ov.style.inset = '0';
          ov.style.background = 'rgba(6,10,16,.65)';
          ov.style.backdropFilter = 'blur(2px)';
          ov.style.zIndex = '34';
          ov.addEventListener('click', closeSidebar);
          document.body.appendChild(ov);
      }
      function removeOverlay() {
          document.getElementById(overlayId)?.remove();
      }
      function openSidebar() {
          sidebar?.classList.add('is-open');
          addOverlay();
      }
      function closeSidebar() {
          sidebar?.classList.remove('is-open');
          removeOverlay();
      }
      openBtn?.addEventListener('click', openSidebar);
      document.addEventListener('keydown', (e) => {if (e.key === 'Escape') closeSidebar();});
      window.matchMedia('(max-width: 1024px)').addEventListener('change', (e) => {if (!e.matches) closeSidebar();});
  });
</script>
@yield('extra-js')