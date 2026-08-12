@extends('layouts.console')
@section('title', 'Activity — store '.$storeId)

@section('content')

<div class="card pad">
  <h1>Activity — store #{{ $storeId }}</h1>
  <p class="note" style="margin-top:4px">
    Every change to a shift, a punch, a request or a day close, newest first.
    Append-only: rows are never edited or removed, which is the only thing that
    makes it worth consulting when two managers disagree about what happened.
  </p>
  <form method="GET" action="{{ route('board.activity') }}" class="ctl" style="margin-top:10px">
    <label class="f"><span class="lbl">Store</span>
      <select name="store">
        @foreach ($stores as $s)
          <option value="{{ $s->id }}" @selected($s->id === $storeId)>#{{ $s->id }} — {{ $s->store_number }}</option>
        @endforeach
      </select>
    </label>
    <button>Go</button>
  </form>
</div>

@include('board._activity', ['entries' => $entries, 'heading' => 'All activity'])

@endsection
