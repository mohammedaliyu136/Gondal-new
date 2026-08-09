{{--
  Validation errors, shown INSIDE the modal that produced them.

  The page's flash block sits at the top of the content, underneath the modal's
  own fixed overlay. So when a modal reopens to preserve typed work, the reason it
  reopened is rendered behind it and cannot be read: the operator sees a form that
  apparently refused to close and says nothing about why.

  Only the modal that was submitted shows them — `_modal` names it — so two modals
  on one screen cannot both claim the same error.

  @param string $modal  the id of the modal this block sits in
--}}
@if ($errors->any() && old('_modal') === $modal)
  <div class="alert danger mb-16">
    <span>&#10060;</span>
    <div>
      <ul style="margin:0;padding-left:16px">
        @foreach ($errors->all() as $message)
          <li>{{ $message }}</li>
        @endforeach
      </ul>
    </div>
  </div>
@endif
