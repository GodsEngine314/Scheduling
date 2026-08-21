{{-- Employee <option>s carrying the role that will be stored against them.

     THIS IS WHERE THE POSITION DROPDOWN WENT. It used to sit beside the employee
     picker and ask a manager to name the role — which hiring already holds on the
     person's profile, and which TCP has already assigned per person, per store.
     Asking produced failures the vendors only reported afterwards: roles TCP has
     nowhere (Driver, Insider, Shift Lead), roles it has at one store only
     (Management), and hours filed under a role nobody was employed as.

     REMOVING A FIELD MUST NOT REMOVE THE FACT. Whoever picked a position at
     least knew what the shift would carry, so the role moved onto the name
     rather than disappearing:

         Ada Lovelace — Crew Member
         Ben Fisher — Driver · no TCP job code
         Cleo Nash — no role on their profile

     $roster and $jobCodes come from the parent view. Two parameters:

     $for   'plan' (default) reads TCP's assignment, then hiring's profile — the
            order BoardController::plannedPositionId() resolves in, so the option
            says what the shift will actually store. 'punch' reads TCP only,
            because a punch's role exists to build a job code and hiring's answer
            cannot; somebody with no code is disabled there and says why while the
            form is still open, instead of failing on save.

     $selectedId  preselects one employee, for the edit forms. --}}
@php
    $for = $for ?? 'plan';
    $selectedId = $selectedId ?? null;
@endphp
@foreach ($roster as $r)
  @php
      $employee = $r['model'];
      $tcp = $jobCodes[$employee->id]['tcp'] ?? null;
      $hiring = $jobCodes[$employee->id]['hiring'] ?? null;

      // What this form will store. Same precedence as the server, so the label
      // cannot promise something the save then contradicts.
      $role = $for === 'punch' ? $tcp : ($tcp ?? $hiring);
  @endphp
  <option value="{{ $employee->id }}"
          @selected($employee->id === $selectedId)
          @disabled($for === 'punch' && $tcp === null)
          @if ($tcp) title="TCP job code {{ $tcp['code'] }}" @endif>
    {{ $employee->fullName() }}
    @if ($role === null)
      {{-- Not decoration. On a punch form this option is disabled, and without
           the words it would just look broken.

           TWO DIFFERENT ABSENCES, worded differently on purpose. On the punch
           form the missing thing is specifically a TCP code — hiring may well
           know the role, and saying "no role" there would send somebody to fix
           the wrong system. On the plan form neither source has an answer, so
           the shift saves roleless and cannot publish, which is worth knowing
           before it is created rather than at publish. --}}
      @if ($for === 'punch') — no TCP job code @else — no role on their profile @endif
    @else
      — {{ $role['label'] }}
      @if ($tcp === null)
        {{-- Hiring knows the role; the timeclock has not assigned a code for it
             here. The shift plans fine and the hours will not file, which is the
             half of the story the plan form would otherwise hide. --}}
        · no TCP job code
      @endif
    @endif
  </option>
@endforeach
