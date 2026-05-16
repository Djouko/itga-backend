@extends('include.app')
@section('content')
<section class="section">
    <div class="row">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Total logs</div>
                    <h4>{{ $kpis['total_logs'] }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Dernieres 24h</div>
                    <h4>{{ $kpis['logs_last_24h'] }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Moderatrices actives (30j)</div>
                    <h4>{{ $kpis['unique_moderators_30d'] }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Resultats filtres</div>
                    <h4>{{ $kpis['filtered_logs'] }}</h4>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="page-title w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-normal d-flex align-items-center">Moderation audit trail</h4>
                    <a href="{{ route('moderationAuditLogs') }}" class="btn btn-light">Reset</a>
                </div>
            </div>
        </div>
        <div class="card-body">
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <form method="GET" action="{{ route('moderationAuditLogs') }}" class="row g-2 mb-4">
                <div class="col-md-3">
                    <input type="text" name="keyword" class="form-control" placeholder="Action, IP, cible..." value="{{ request('keyword') }}">
                </div>
                <div class="col-md-2">
                    <select name="action" class="form-control">
                        <option value="">Toutes actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="target_type" class="form-control">
                        <option value="">Toutes cibles</option>
                        @foreach ($targetTypes as $targetType)
                            <option value="{{ $targetType }}" {{ request('target_type') === $targetType ? 'selected' : '' }}>{{ $targetType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="number" min="1" name="moderator_user_id" class="form-control" placeholder="ID moderatrice" value="{{ request('moderator_user_id') }}">
                </div>
                <div class="col-md-1">
                    <select name="status" class="form-control">
                        <option value="">Statut</option>
                        @foreach (['success' => 'success', 'failed' => 'failed'] as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" {{ request('status') === $statusValue ? 'selected' : '' }}>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}">
                </div>
                <div class="col-md-2">
                    <select name="per_page" class="form-control">
                        @foreach ([20, 50, 100] as $size)
                            <option value="{{ $size }}" {{ (int) request('per_page', 20) === $size ? 'selected' : '' }}>{{ $size }} / page</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">Filtrer</button>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('moderationAuditLogs') }}" class="btn btn-light w-100">Vider</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped w-100">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Moderatrice</th>
                            <th>Action</th>
                            <th>Cible</th>
                            <th>Owner cible</th>
                            <th>Statut</th>
                            <th>Reseau</th>
                            <th>Metadata</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            @php
                                $moderator = $userLookup->get((int) $log->moderator_user_id);
                                $targetOwner = $userLookup->get((int) $log->target_owner_user_id);
                            @endphp
                            <tr>
                                <td>{{ optional($log->created_at)->format('d-m-Y H:i:s') }}</td>
                                <td>
                                    @if ($moderator)
                                        <strong>{{ $moderator->full_name ?: $moderator->username ?: $moderator->identity }}</strong>
                                        <div class="text-muted small">#{{ $moderator->id }}</div>
                                    @else
                                        <span class="text-muted">#{{ $log->moderator_user_id }}</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-secondary">{{ $log->action }}</span></td>
                                <td>
                                    <div><strong>{{ $log->target_type }}</strong></div>
                                    <div class="text-muted small">ID: {{ $log->target_id ?? 'n/a' }}</div>
                                </td>
                                <td>
                                    @if ($targetOwner)
                                        <strong>{{ $targetOwner->full_name ?: $targetOwner->username ?: $targetOwner->identity }}</strong>
                                        <div class="text-muted small">#{{ $targetOwner->id }}</div>
                                    @elseif (!is_null($log->target_owner_user_id))
                                        <span class="text-muted">#{{ $log->target_owner_user_id }}</span>
                                    @else
                                        <span class="text-muted">n/a</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $log->status === 'success' ? 'bg-success' : 'bg-danger' }}">
                                        {{ $log->status }}
                                    </span>
                                </td>
                                <td>
                                    <div class="small">IP: {{ $log->ip_address ?: 'n/a' }}</div>
                                    <div class="text-muted small" style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        {{ $log->user_agent ?: 'n/a' }}
                                    </div>
                                </td>
                                <td style="min-width: 280px;">
                                    @if (!empty($log->metadata))
                                        <details>
                                            <summary class="text-primary">Voir</summary>
                                            <pre class="mb-0 small">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Aucun log de moderation trouve.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $logs->links() }}</div>
        </div>
    </div>
</section>
@endsection
