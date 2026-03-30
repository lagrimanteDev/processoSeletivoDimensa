<x-app-layout>
	<x-slot name="header">
		<div class="flex items-center justify-between">
			<h2 class="font-semibold text-xl text-gray-800 leading-tight">
				Operações
			</h2>
		</div>
	</x-slot>

	<div class="py-8">
		<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
			@if (session('status'))
				<div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded">
					{{ session('status') }}
				</div>
			@endif

			@if ($errors->any())
				<div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded">
					<ul class="list-disc list-inside">
						@foreach ($errors->all() as $error)
							<li>{{ $error }}</li>
						@endforeach
					</ul>
				</div>
			@endif

			<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
				<h3 class="text-lg font-medium text-gray-900 mb-4">Importar planilha</h3>
				<form id="import-form" action="{{ route('operacoes.import') }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
					@csrf
					<input type="file" name="arquivo" accept=".xlsx,.xls,.csv" class="border-gray-300 rounded-md shadow-sm" required>
					<x-primary-button id="import-button">Importar</x-primary-button>
				</form>
				<p id="import-warning" class="hidden mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
					Importação iniciada. Este processo pode levar alguns minutos.
				</p>

				<div class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
					<div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
						<div>
							<h4 class="text-sm font-semibold text-indigo-900">Acompanhamento da importação</h4>
							<p id="import-latest-file" class="text-xs text-indigo-800 mt-1">
								@if ($importStats['latest_file'])
									Arquivo: {{ $importStats['latest_file'] }}
								@else
									Nenhuma importação recente encontrada.
								@endif
							</p>
						</div>
						<span id="import-status-badge" class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium
							{{ $importStats['is_running'] ? 'bg-amber-100 text-amber-800' : ($importStats['is_completed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-green-100 text-green-800') }}">
							{{ $importStats['is_running'] ? 'Processando...' : ($importStats['is_completed'] ? 'Importação concluída' : 'Sem processamento em andamento') }}
						</span>
					</div>

					<div class="mt-3 h-2 w-full rounded bg-indigo-100">
						<div id="import-progress-bar" class="h-2 rounded bg-indigo-600" style="width: {{ $importStats['progress'] }}%"></div>
					</div>
					<p id="import-progress-text" class="mt-2 text-xs text-indigo-900">{{ $importStats['processed'] }} de {{ $importStats['total'] }} linhas concluídas ({{ $importStats['progress'] }}%)</p>

					<div class="mt-3 grid grid-cols-2 md:grid-cols-6 gap-2 text-xs">
						<div class="rounded border border-slate-200 bg-white px-3 py-2">
							<div class="text-slate-500">Total</div>
							<div id="import-total" class="font-semibold text-slate-900">{{ $importStats['total'] }}</div>
						</div>
						<div class="rounded border border-violet-200 bg-white px-3 py-2">
							<div class="text-violet-700">Jobs (DB)</div>
							<div id="import-jobs" class="font-semibold text-violet-900">{{ $importStats['jobs_pending'] }}</div>
						</div>
						<div class="rounded border border-amber-200 bg-white px-3 py-2">
							<div class="text-amber-700">Fila</div>
							<div id="import-queued" class="font-semibold text-amber-900">{{ $importStats['queued'] }}</div>
						</div>
						<div class="rounded border border-blue-200 bg-white px-3 py-2">
							<div class="text-blue-700">Processando</div>
							<div id="import-processing" class="font-semibold text-blue-900">{{ $importStats['processing'] }}</div>
						</div>
						<div class="rounded border border-green-200 bg-white px-3 py-2">
							<div class="text-green-700">Sucesso</div>
							<div id="import-success" class="font-semibold text-green-900">{{ $importStats['success'] }}</div>
						</div>
						<div class="rounded border border-red-200 bg-white px-3 py-2">
							<div class="text-red-700">Erros</div>
							<div id="import-error" class="font-semibold text-red-900">{{ $importStats['error'] }}</div>
						</div>
					</div>

					<div id="import-errors-box" class="mt-3 rounded border border-red-200 bg-red-50 p-3 {{ $importStats['recent_errors']->isNotEmpty() ? '' : 'hidden' }}">
							<p class="text-xs font-semibold text-red-900 mb-2">Últimos erros</p>
							<ul id="import-errors-list" class="space-y-1 text-xs text-red-800">
								@foreach ($importStats['recent_errors'] as $importError)
									<li>Linha {{ $importError->linha }}: {{ $importError->mensagem }}</li>
								@endforeach
							</ul>
						</div>
				</div>
			</div>

			<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
				<form method="GET" action="{{ route('operacoes.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
					<div>
						<label class="block text-sm text-gray-700 mb-1">Código</label>
						<input type="text" name="codigo" value="{{ request('codigo') }}" class="w-full border-gray-300 rounded-md shadow-sm">
					</div>

					<div>
						<label class="block text-sm text-gray-700 mb-1">Cliente (nome ou CPF)</label>
						<input type="text" name="cliente" value="{{ request('cliente') }}" class="w-full border-gray-300 rounded-md shadow-sm">
					</div>

					<div>
						<label class="block text-sm text-gray-700 mb-1">Status</label>
						<select name="status" class="w-full border-gray-300 rounded-md shadow-sm">
							<option value="">Todos</option>
							@foreach ($statuses as $status)
								<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
							@endforeach
						</select>
					</div>

					<div class="flex items-end gap-2">
						<x-primary-button>Filtrar</x-primary-button>
						<a href="{{ route('operacoes.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-200">
							Limpar
						</a>
					</div>
				</form>

				<div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50/60 p-4">
					<div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
						<div>
							<h4 class="text-sm font-semibold text-emerald-900">Geração de relatório</h4>
						</div>

						<div class="flex flex-wrap items-center gap-2">
							<a href="{{ route('operacoes.report', request()->query()) }}" class="inline-flex items-center px-4 py-2 bg-white  border border-emerald-700 rounded-md text-sm text-gray-700 hover:bg-emerald-700">
								Gerar relatório (com filtros)
							</a>
							<a href="{{ route('operacoes.report') }}" class="inline-flex items-center px-4 py-2  border bg-emerald-600 border-emerald-300 rounded-md text-sm text-emerald-800 hover:bg-emerald-100">
								Gerar relatório (completo)
							</a>
						</div>
					</div>
				</div>

				<div class="overflow-x-auto">
					<table class="min-w-full divide-y divide-gray-200 text-sm">
						<thead class="bg-gray-50">
							<tr>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Código</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Cliente</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">CPF</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Conveniada</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Valor da operação</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Status</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Produto</th>
								<th class="px-3 py-2 text-left font-semibold text-gray-700">Ações</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							@forelse ($operacoes as $operacao)
								<tr>
									<td class="px-3 py-2">{{ $operacao->codigo }}</td>
									<td class="px-3 py-2">{{ $operacao->cliente?->nome }}</td>
									<td class="px-3 py-2">{{ $operacao->cliente?->cpf }}</td>
									<td class="px-3 py-2">{{ $operacao->conveniada?->nome }}</td>
									<td class="px-3 py-2">R$ {{ number_format((float) ($operacao->valor_desembolso ?: $operacao->valor_requerido), 2, ',', '.') }}</td>
									<td class="px-3 py-2">{{ $operacao->status }}</td>
									<td class="px-3 py-2">{{ $operacao->produto }}</td>
									<td class="px-3 py-2">
										<a href="{{ route('operacoes.show', $operacao) }}" class="text-indigo-600 hover:text-indigo-800">Detalhes</a>
									</td>
								</tr>
							@empty
								<tr>
									<td colspan="8" class="px-3 py-6 text-center text-gray-500">Nenhuma operação encontrada.</td>
								</tr>
							@endforelse
						</tbody>
					</table>
				</div>

				<div class="mt-4">
					{{ $operacoes->links() }}
				</div>
			</div>
		</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			const form = document.getElementById('import-form');
			const warning = document.getElementById('import-warning');
			const button = document.getElementById('import-button');
			const statsUrl = '{{ route('operacoes.import-stats') }}';

			if (!form || !warning || !button) {
				return;
			}

			form.addEventListener('submit', function () {
				warning.classList.remove('hidden');
				button.disabled = true;
				button.textContent = 'Importando...';
			});

			const statusBadge = document.getElementById('import-status-badge');
			const latestFile = document.getElementById('import-latest-file');
			const progressBar = document.getElementById('import-progress-bar');
			const progressText = document.getElementById('import-progress-text');
			const totalEl = document.getElementById('import-total');
			const jobsEl = document.getElementById('import-jobs');
			const queuedEl = document.getElementById('import-queued');
			const processingEl = document.getElementById('import-processing');
			const successEl = document.getElementById('import-success');
			const errorEl = document.getElementById('import-error');
			const errorsBox = document.getElementById('import-errors-box');
			const errorsList = document.getElementById('import-errors-list');

			function applyStatusBadge(data) {
				if (!statusBadge) return;

				statusBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium';

				if (data.is_running) {
					statusBadge.classList.add('bg-amber-100', 'text-amber-800');
					statusBadge.textContent = 'Processando...';
					return;
				}

				if (data.is_completed) {
					statusBadge.classList.add('bg-emerald-100', 'text-emerald-800');
					statusBadge.textContent = 'Importação concluída';
					return;
				}

				statusBadge.classList.add('bg-green-100', 'text-green-800');
				statusBadge.textContent = 'Sem processamento em andamento';
			}

			function applyErrors(data) {
				if (!errorsBox || !errorsList) return;

				errorsList.innerHTML = '';

				if (!data.recent_errors || data.recent_errors.length === 0) {
					errorsBox.classList.add('hidden');
					return;
				}

				data.recent_errors.forEach(function (item) {
					const li = document.createElement('li');
					li.textContent = 'Linha ' + item.linha + ': ' + item.mensagem;
					errorsList.appendChild(li);
				});

				errorsBox.classList.remove('hidden');
			}

			function updateStats() {
				fetch(statsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
					.then(function (response) { return response.json(); })
					.then(function (data) {
						if (latestFile) {
							latestFile.textContent = data.latest_file ? ('Arquivo: ' + data.latest_file) : 'Nenhuma importação recente encontrada.';
						}

						if (progressBar) {
							progressBar.style.width = data.progress + '%';
						}

						if (progressText) {
							progressText.textContent = data.processed + ' de ' + data.total + ' linhas concluídas (' + data.progress + '%)';
						}

						if (totalEl) totalEl.textContent = data.total;
						if (jobsEl) jobsEl.textContent = data.jobs_pending;
						if (queuedEl) queuedEl.textContent = data.queued;
						if (processingEl) processingEl.textContent = data.processing;
						if (successEl) successEl.textContent = data.success;
						if (errorEl) errorEl.textContent = data.error;

						applyStatusBadge(data);
						applyErrors(data);
					});
			}

			setInterval(updateStats, 5000);
		});
	</script>
</x-app-layout>
