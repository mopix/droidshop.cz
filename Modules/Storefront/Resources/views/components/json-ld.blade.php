@props(['data'])

{{-- JSON_HEX_TAG matters: a product name containing </script> would otherwise
     close the block and turn shop content into markup. --}}
<script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
