@extends('include.app')
@section('content')
<section class="section">
    <div class="row">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Entreprises</div><h4>{{ $kpis['total_companies'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Vérifiées</div><h4>{{ $kpis['verified_companies'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Suspendues</div><h4>{{ $kpis['suspended_companies'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Offres</div><h4>{{ $kpis['total_offers'] }}</h4></div></div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="page-title w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-normal d-flex align-items-center">Entreprises ITGA</h4>
                    <a href="{{ route('adminJobs') }}" class="btn btn-primary">Voir jobs</a>
                </div>
            </div>
        </div>
        <div class="card-body">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <form method="GET" action="{{ route('adminCompanies') }}" class="row g-2 mb-4">
                <div class="col-md-5">
                    <input type="text" name="keyword" class="form-control" placeholder="Nom, email, secteur, owner..." value="{{ request('keyword') }}">
                </div>
                <div class="col-md-3">
                    <select name="suspended" class="form-control">
                        <option value="">Toutes</option>
                        <option value="0" {{ request('suspended') === '0' ? 'selected' : '' }}>Actives</option>
                        <option value="1" {{ request('suspended') === '1' ? 'selected' : '' }}>Suspendues</option>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filtrer</button></div>
                <div class="col-md-2"><a href="{{ route('adminCompanies') }}" class="btn btn-light w-100">Reset</a></div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped w-100">
                    <thead>
                        <tr>
                            <th>Entreprise</th>
                            <th>Owner</th>
                            <th>Secteur</th>
                            <th>Ville</th>
                            <th>Offres</th>
                            <th>Publiées</th>
                            <th>Statut</th>
                            <th style="text-align: right; width: 260px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($companies as $company)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <img src="{{ $company->logo ?: asset('asset/img/default.png') }}" alt="" style="width: 42px; height: 42px; object-fit: cover; border-radius: 50%;" class="me-2">
                                        <div>
                                            <strong>{{ $company->name }}</strong>
                                            <div class="text-muted small">{{ $company->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if ($company->owner)
                                        <a href="{{ url('usersDetail/' . $company->owner->id) }}">{{ $company->owner->full_name }}</a>
                                        <div class="text-muted small">{{ $company->owner->username }}</div>
                                    @else
                                        <span class="text-muted">Aucun owner lié</span>
                                    @endif
                                </td>
                                <td>{{ $company->sector ?: '-' }}</td>
                                <td>{{ trim(($company->city ?? '') . ' ' . ($company->country ?? '')) ?: '-' }}</td>
                                <td>{{ $company->job_offers_count }}</td>
                                <td>{{ $company->published_offers_count }}</td>
                                <td>
                                    @if ($company->is_suspended)
                                        <span class="badge bg-danger">Suspendue</span>
                                    @else
                                        <span class="badge bg-success">Active</span>
                                    @endif
                                    @if ($company->is_verified)
                                        <span class="badge bg-info">Certifiee ITGA</span>
                                    @endif
                                    @if ($company->email_verified_at)
                                        <span class="badge bg-secondary">Email valide</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('adminToggleVerifyCompanyWeb') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="company_id" value="{{ $company->id }}">
                                        <button type="submit" class="btn btn-sm {{ $company->is_verified ? 'btn-outline-info' : 'btn-info' }}">
                                            {{ $company->is_verified ? 'Retirer badge' : 'Certifier' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('adminToggleSuspendCompanyWeb') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="company_id" value="{{ $company->id }}">
                                        <button type="submit" class="btn btn-sm {{ $company->is_suspended ? 'btn-success' : 'btn-danger' }}">
                                            {{ $company->is_suspended ? 'Réactiver' : 'Suspendre' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">Aucune entreprise trouvée.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $companies->links() }}</div>
        </div>
    </div>
</section>
@endsection
