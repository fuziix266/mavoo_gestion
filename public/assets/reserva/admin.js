// --- CONFIGURACIÓN ---
let AppConfig = window.SERVER_CONFIG || {};
let currentDate = new Date().toISOString().split('T')[0];
let currentContext = null;
const SYSTEM_SLUG = AppConfig.SLUG || 'umm2';
window.currentBookingsCache = []; // Caché para no re-consultar en cada cambio de tab

document.addEventListener('DOMContentLoaded', () => {
    if (!AppConfig.CANCHAS) {
        console.error("Falta configuración de canchas");
        return;
    }
    
    // Auto-reparación
    if (!AppConfig.CUPONES) AppConfig.CUPONES = [];
    if (!AppConfig.AMENIDADES) AppConfig.AMENIDADES = [];
    if (!AppConfig.EXTRAS) AppConfig.EXTRAS = [];

    // Helper API Fetch
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    const token = tokenMeta ? tokenMeta.content : '';
    
    window.apiFetch = async (url, method, body) => {
        // 1. Buscamos el token en el HTML
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        const token = tokenMeta ? tokenMeta.content : '';
        
        // 2. Lo agregamos al Header
        const opts = { 
            method, 
            headers: { 
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token  // <--- ESTO ES "EL CODIGO" @csrf
            } 
        };
        
        if(body) opts.body = JSON.stringify({ slug: SYSTEM_SLUG, ...body });
        
        try {
            const r = await fetch(url, opts);
            if(!r.ok) {
                const errJson = await r.json().catch(()=>({}));
                // Si el error es 403, probablemente es el filtro isAdmin(), no el CSRF.
                throw new Error(errJson.error || r.statusText);
            }
            return await r.json();
        } catch (e) {
            console.error(e);
            alert("Error: " + e.message);
            throw e;
        }
    };

    // Inicializar fecha
    const dInput = document.getElementById('adminDateInput');
    if (dInput) dInput.value = currentDate;

    // Render Inicial Completo
    renderCalendar();       
    renderConfigForm();     
    renderExtrasEditor();   
    renderCourtsEditor();   
    renderAmenitiesManager(); 
    renderCoupons();        
    
    // Estos dependen de las reservas, se llaman tras renderCalendar
    renderBalance();
    renderClients();
    renderAnalytics();
});

// --- NAVEGACIÓN (MENÚS) ---
window.showSection = function(id) {
    document.querySelectorAll('.admin-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    
    const section = document.getElementById(`sec-${id}`);
    if (section) section.classList.add('active');
    
    if (event && event.target) {
        const btn = event.target.closest('button');
        if (btn) btn.classList.add('active');
    }
    
    if (id === 'analytics') renderAnalytics();
    if (id === 'clients') renderClients();
    if (id === 'balance') renderBalance();
};

window.changeAdminDate = function(days) {
    const d = new Date(currentDate);
    d.setDate(d.getDate() + days);
    currentDate = d.toISOString().split('T')[0];
    const dInput = document.getElementById('adminDateInput');
    if(dInput) dInput.value = currentDate;
    renderCalendar(); 
}

document.getElementById('adminDateInput')?.addEventListener('change', (e) => {
    currentDate = e.target.value;
    renderCalendar();
});


// --- 1. CALENDARIO ADMIN (CONECTADO A BD) ---
async function renderCalendar() {
    const table = document.getElementById('adminScheduleTable');
    if (!table) return;
    table.innerHTML = '<tr><td style="color:white;text-align:center;padding:20px">Cargando datos...</td></tr>';

    try {
        const bookings = await window.apiFetch(`/api/bookings?slug=${SYSTEM_SLUG}&date=${currentDate}`, 'GET');
        window.currentBookingsCache = bookings; // Actualizar caché global
    } catch (e) {
        table.innerHTML = '<tr><td style="color:var(--danger);text-align:center;padding:20px">Error de conexión</td></tr>';
        return;
    }

    const courts = AppConfig.CANCHAS || [];
    let html = `<thead><tr><th>Hora</th>${courts.map(c => `<th>${c.nombre}</th>`).join('')}</tr></thead><tbody>`;
    
    const startMins = parseInt(AppConfig.HORA_INICIO.split(':')[0]) * 60 + parseInt(AppConfig.HORA_INICIO.split(':')[1]);
    let endMins = parseInt(AppConfig.HORA_FIN.split(':')[0]) * 60 + parseInt(AppConfig.HORA_FIN.split(':')[1]);
    const interval = parseInt(AppConfig.INTERVALO_MIN);
    if (endMins <= startMins) endMins += 1440;

    let current = startMins;
    
    while (current + interval <= endMins) {
        const startStr = minutesToTime(current);
        
        html += `<tr><td class="cell-time">${startStr}</td>`;
        
        courts.forEach(court => {
            const booking = window.currentBookingsCache.find(b => 
                parseInt(b.courtId) === court.id && 
                b.timeStart === startStr && 
                b.status !== 'cancelled'
            );

            if (booking) {
                const isBlocked = booking.status === 'blocked';
                const style = isBlocked ? 'background:#333;color:#777' : '';
                const label = isBlocked ? 'BLOQUEADO' : (booking.adminCreated ? 'ADMIN' : 'OCUPADO');
                const paidIcon = booking.isPaid ? '✅' : '';
                
                html += `<td class="cell-booked" style="${style}" onclick="openAdminModal('booked', ${booking.id})">
                            ${paidIcon} ${label}<br><small>${booking.clientName || ''}</small>
                         </td>`;
            } else {
                html += `<td class="cell-free" onclick="openAdminModal('free', null, ${court.id}, '${startStr}', '${minutesToTime(current+interval)}')">Libre</td>`;
            }
        });
        html += `</tr>`;
        current += interval;
    }
    table.innerHTML = html + '</tbody>';
    
    // Actualizar módulos dependientes
    renderBalance();
    renderClients();
    renderAnalytics();
}

// --- 2. MODALES Y ACCIONES BD ---
window.openAdminModal = function(type, bookingId, courtId, start, end) {
    const modal = document.getElementById('adminActionModal');
    if (!modal) return;
    
    document.getElementById('actionsFree').style.display = 'none';
    document.getElementById('actionsBooked').style.display = 'none';
    modal.classList.add('active');

    if (type === 'free') {
        currentContext = { type: 'free', courtId, start, end };
        document.getElementById('modalTitle').innerText = "Nuevo Bloque";
        const court = AppConfig.CANCHAS.find(c => c.id === courtId);
        document.getElementById('modalInfo').innerHTML = `<strong>${court ? court.nombre : ''}</strong><br>${start} - ${end}`;
        document.getElementById('actionsFree').style.display = 'flex';
    } else {
        const booking = window.currentBookingsCache.find(b => b.id === bookingId);
        if(!booking) { closeAdminModal(); return; }
        
        currentContext = { type: 'booked', booking };
        const isBlocked = booking.status === 'blocked';
        document.getElementById('modalTitle').innerText = isBlocked ? "Bloqueo" : "Reserva";
        
        const info = `
            <strong>Cliente:</strong> ${booking.clientName || 'N/A'}<br>
            <strong>Total:</strong> $${formatMoney(booking.totalPrice)}<br>
            <strong>Estado:</strong> ${booking.isPaid ? '<span style="color:#00ff88">PAGADO</span>' : '<span style="color:#ff0055">PENDIENTE</span>'}
        `;
        document.getElementById('modalInfo').innerHTML = info;

        const btnPay = document.getElementById('btnTogglePay');
        if (isBlocked) btnPay.style.display = 'none';
        else {
            btnPay.style.display = 'flex';
            if(booking.isPaid) {
                 btnPay.innerHTML = '<i class="fa-solid fa-xmark"></i> Desmarcar Pago';
                 btnPay.style.background = '#555';
            } else {
                 btnPay.innerHTML = '<i class="fa-solid fa-check"></i> Marcar PAGADO';
                 btnPay.style.background = '#00cc66';
            }
        }
        document.getElementById('actionsBooked').style.display = 'flex';
    }
};

window.executeAction = async function(action) {
    if (!currentContext) return;
    
    try {
        if (currentContext.type === 'free') {
            const court = AppConfig.CANCHAS.find(c => c.id === currentContext.courtId);
            const price = court.precio || AppConfig.PRECIO_BASE;
            
            const body = {
                courtId: currentContext.courtId,
                date: currentDate,
                timeStart: currentContext.start,
                timeEnd: currentContext.end,
                status: action === 'block' ? 'blocked' : 'booked',
                totalPrice: action === 'block' ? 0 : price,
                isPaid: action === 'reserve', 
                paymentMethod: 'Efectivo',
                clientName: action === 'block' ? 'BLOQUEO' : 'Reserva Admin',
                clientPhone: '-',
                adminCreated: true
            };
            
            await window.apiFetch('/api/bookings', 'POST', body);

        } else if (currentContext.type === 'booked') {
            const id = currentContext.booking.id;
            
            if (action === 'cancel') {
                if(!confirm("¿Eliminar definitivamente?")) return;
                await window.apiFetch(`/api/bookings/${id}`, 'DELETE', {});
            } else if (action === 'togglePay') {
                const newState = !currentContext.booking.isPaid;
                await window.apiFetch(`/api/bookings/${id}`, 'PUT', { isPaid: newState, paymentMethod: 'Efectivo' });
            }
        }
        
        closeAdminModal();
        renderCalendar(); 
    } catch (e) {
        alert("Error procesando acción");
    }
};

window.closeAdminModal = () => document.getElementById('adminActionModal').classList.remove('active');


// --- 3. GESTIÓN DE CANCHAS (CRUD REAL) ---
function renderCourtsEditor() {
    const container = document.getElementById('adminCourtsList');
    if(!container) return;
    container.innerHTML = (AppConfig.CANCHAS || []).map((c) => {
        const amIcons = (c.amenidades || []).map(id => {
            const am = (AppConfig.AMENIDADES || []).find(a => a.id === id);
            return am ? `<i class="fa-solid ${am.icon}" style="color:#889;margin-right:5px"></i>` : '';
        }).join('');

        return `
        <div class="admin-court-card">
            <div style="position:relative;">
                <img src="${c.imagen || 'https://via.placeholder.com/150'}" style="width:100%;height:120px;object-fit:cover;border-radius:5px">
                <div style="position:absolute;bottom:5px;right:5px;background:rgba(0,0,0,0.7);padding:2px;border-radius:4px;font-size:0.8rem;">$${c.precio ? formatMoney(c.precio) : 'Base'}</div>
            </div>
            <h3 style="margin:10px 0 5px;">${c.nombre}</h3>
            <div style="margin-bottom:10px;">${amIcons}</div>
            <div style="display:flex; gap:10px;">
                <button class="btn-save" style="flex:1" onclick="openCourtModal(${c.id})">Editar</button>
                <button class="btn-cancel" style="flex:1" onclick="deleteCourt(${c.id})"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`;
    }).join('');
}

window.openCourtModal = function(id = null) {
    const modal = document.getElementById('modalCourt');
    if(!modal) return;
    document.getElementById('courtForm').reset();
    let amenities = [];
    
    if (id) {
        const c = AppConfig.CANCHAS.find(x => x.id === id);
        if(c) {
            document.getElementById('courtId').value = c.id; 
            document.getElementById('courtName').value = c.nombre;
            document.getElementById('courtImage').value = c.imagen;
            document.getElementById('courtPrice').value = c.precio || 0;
            amenities = c.amenidades || [];
        }
    } else {
        document.getElementById('courtId').value = ""; 
    }

    const grid = document.getElementById('courtAmenitiesGrid');
    grid.innerHTML = (AppConfig.AMENIDADES || []).map(a => `
        <div class="amenity-option ${amenities.includes(a.id)?'selected':''}" onclick="this.classList.toggle('selected'); this.querySelector('input').checked = !this.querySelector('input').checked;">
            <i class="fa-solid ${a.icon}"></i> <span>${a.nombre}</span>
            <input type="checkbox" value="${a.id}" ${amenities.includes(a.id)?'checked':''} style="display:none">
        </div>`).join('');
    modal.classList.add('active');
};

window.saveCourt = async function(e) {
    e.preventDefault();
    const idVal = document.getElementById('courtId').value;
    const data = {
        id: idVal ? parseInt(idVal) : null,
        nombre: document.getElementById('courtName').value,
        imagen: document.getElementById('courtImage').value,
        precio: parseInt(document.getElementById('courtPrice').value) || 0,
        amenidades: Array.from(document.querySelectorAll('#courtAmenitiesGrid input:checked')).map(cb => cb.value)
    };

    try {
        await window.apiFetch('/api/admin/court', 'POST', data);
        location.reload(); 
    } catch(err) {
        console.error(err);
        alert("Error al guardar: " + err.message);
    }
};

window.deleteCourt = async function(id) {
    if(!confirm("¿Eliminar cancha de la BD? Se borrarán sus reservas.")) return;
    try {
        await window.apiFetch(`/api/admin/court/${id}`, 'DELETE', {});
        location.reload();
    } catch(err) {
        alert("Error al eliminar");
    }
};

window.addNewCourt = () => window.openCourtModal(); 
window.closeCourtModal = () => document.getElementById('modalCourt').classList.remove('active');

// --- 4. GESTIÓN DE AMENIDADES ---
function renderAmenitiesManager() {
    const el = document.getElementById('adminAmenitiesManager');
    if(!el) return;
    el.innerHTML = (AppConfig.AMENIDADES||[]).map((a, i) => `
        <div class="amenity-row">
            <div class="icon-preview"><i class="fa-solid ${a.icon}"></i></div>
            <input type="text" value="${a.nombre}" id="am_name_${i}">
            <input type="text" value="${a.icon}" id="am_icon_${i}" oninput="this.parentElement.querySelector('.icon-preview i').className='fa-solid '+this.value">
            <button class="btn-save" onclick="saveAmenitiesBD(${i})"><i class="fa-solid fa-save"></i></button>
            <button class="btn-del" onclick="deleteAmenityBD(${i})"><i class="fa-solid fa-trash"></i></button>
        </div>
    `).join('') + `<button class="btn-add" onclick="addNewAmenityBD()">+ Nueva Amenidad</button>`;
}
window.saveAmenitiesBD = async function(idx) {
    AppConfig.AMENIDADES[idx].nombre = document.getElementById(`am_name_${idx}`).value;
    AppConfig.AMENIDADES[idx].icon = document.getElementById(`am_icon_${idx}`).value;
    await window.apiFetch('/api/admin/amenities', 'POST', { amenities: AppConfig.AMENIDADES });
    alert("Guardado");
};
window.addNewAmenityBD = async function() {
    AppConfig.AMENIDADES.push({ id: 'am_'+Date.now(), nombre: 'Nueva', icon: 'fa-star' });
    await window.apiFetch('/api/admin/amenities', 'POST', { amenities: AppConfig.AMENIDADES });
    location.reload();
};
window.deleteAmenityBD = async function(idx) {
    if(!confirm("¿Eliminar?")) return;
    AppConfig.AMENIDADES.splice(idx, 1);
    await window.apiFetch('/api/admin/amenities', 'POST', { amenities: AppConfig.AMENIDADES });
    location.reload();
};

// --- 5. CONFIGURACIÓN ---
function renderConfigForm() {
    const form = document.getElementById('globalConfigForm');
    if(!form) return;
    form.querySelector('[name="HORA_INICIO"]').value = AppConfig.HORA_INICIO;
    form.querySelector('[name="HORA_FIN"]').value = AppConfig.HORA_FIN;
    form.querySelector('[name="INTERVALO_MIN"]').value = AppConfig.INTERVALO_MIN;
    form.querySelector('[name="PRECIO_BASE"]').value = AppConfig.PRECIO_BASE;
    form.onsubmit = async (e) => {
        e.preventDefault();
        const data = {
            HORA_INICIO: form.querySelector('[name="HORA_INICIO"]').value,
            HORA_FIN: form.querySelector('[name="HORA_FIN"]').value,
            INTERVALO_MIN: parseInt(form.querySelector('[name="INTERVALO_MIN"]').value),
            PRECIO_BASE: parseInt(form.querySelector('[name="PRECIO_BASE"]').value)
        };
        await window.apiFetch('/api/admin/config', 'POST', data);
        alert("Configuración Guardada");
        location.reload();
    };
}

function renderExtrasEditor() {
    const el = document.getElementById('adminExtrasList');
    if(!el) return;
    el.innerHTML = (AppConfig.EXTRAS||[]).map((ex, i) => `
        <div class="list-item">
            <input type="text" value="${ex.nombre}" id="ex_name_${i}">
            <input type="number" value="${ex.precio}" id="ex_price_${i}">
            <button class="btn-save" onclick="saveExtrasBD()"><i class="fa-solid fa-save"></i></button>
            <button class="btn-del" onclick="deleteExtraBD(${i})"><i class="fa-solid fa-trash"></i></button>
        </div>`).join('') + `<button class="btn-add" onclick="addExtraBD()">+ Nuevo Extra</button>`;
}
window.saveExtrasBD = async function() {
    const newExtras = AppConfig.EXTRAS.map((ex, i) => ({
        id: ex.id,
        nombre: document.getElementById(`ex_name_${i}`).value,
        precio: parseInt(document.getElementById(`ex_price_${i}`).value)
    }));
    await window.apiFetch('/api/admin/extras', 'POST', { extras: newExtras });
    location.reload();
};
window.addExtraBD = async function() {
    AppConfig.EXTRAS.push({ id: 'ex_'+Date.now(), nombre: 'Nuevo', precio: 0 });
    await window.apiFetch('/api/admin/extras', 'POST', { extras: AppConfig.EXTRAS });
    location.reload();
};
window.deleteExtraBD = async function(i) {
    AppConfig.EXTRAS.splice(i, 1);
    await window.apiFetch('/api/admin/extras', 'POST', { extras: AppConfig.EXTRAS });
    location.reload();
};

// --- 6. CUPONES (UI) ---
function renderCoupons() {
    const tbody = document.getElementById('couponsTableBody');
    if(!tbody) return;
    tbody.innerHTML = (AppConfig.CUPONES || []).map((c, i) => `
        <tr>
            <td><strong style="color:var(--neon)">${c.code}</strong></td>
            <td>${c.discount}%</td>
            <td>${c.uses || 0}</td>
            <td>${c.active ? '<span style="color:var(--success)">Activo</span>' : '<span style="color:var(--danger)">Inactivo</span>'}</td>
            <td>
                <button class="btn-del" style="background:#444" onclick="toggleCoupon(${i})"><i class="fa-solid fa-power-off"></i></button>
                <button class="btn-del" onclick="deleteCoupon(${i})"><i class="fa-solid fa-trash"></i></button>
            </td>
        </tr>`).join('');
}
window.openCouponModal = () => document.getElementById('modalCoupon').classList.add('active');
window.closeCouponModal = () => document.getElementById('modalCoupon').classList.remove('active');
window.saveCoupon = function(e) {
    e.preventDefault();
    const code = document.getElementById('couponCode').value.toUpperCase();
    const discount = parseInt(document.getElementById('couponDiscount').value);
    if(AppConfig.CUPONES.some(c => c.code === code)) { alert("Código existente"); return; }
    AppConfig.CUPONES.push({ code, discount, active: true, uses: 0 });
    closeCouponModal(); renderCoupons();
};
window.toggleCoupon = (i) => { AppConfig.CUPONES[i].active = !AppConfig.CUPONES[i].active; renderCoupons(); };
window.deleteCoupon = (i) => { if(confirm("¿Eliminar?")) { AppConfig.CUPONES.splice(i, 1); renderCoupons(); } };

// --- 7. MÓDULOS DE REPORTE COMPLETOS ---

function renderBalance() {
    const tbody = document.getElementById('balanceTableBody');
    if(!tbody) return;
    
    // Usamos caché global para no re-consultar
    const bookings = window.currentBookingsCache || [];
    const filter = document.getElementById('balanceFilter')?.value || 'month';
    
    let totEst=0, totPaid=0, totPend=0;
    
    const filtered = bookings.filter(b => {
        // Ignoramos cancelados para el cálculo financiero (depende de política, pero usualmente sí)
        if (b.status === 'cancelled') return false; 
        
        // Bloqueos (blocked) valen $0, pero los mostramos si se quiere.
        // Aquí asumimos que balance solo cuenta reservas 'booked' que generan dinero.
        if (b.status === 'blocked') return false; 

        if (filter === 'day') return b.date === currentDate;
        if (filter === 'month') return b.date.substring(0, 7) === currentDate.substring(0, 7);
        return true; // all
    }).sort((a, b) => (b.date + b.timeStart).localeCompare(a.date + a.timeStart));

    let html = '';
    filtered.forEach(b => {
        const p = parseInt(b.totalPrice) || 0; 
        totEst += p;
        if (b.isPaid) totPaid += p; else totPend += p;
        
        // Buscar nombre cancha
        const c = AppConfig.CANCHAS.find(x => x.id === parseInt(b.courtId));
        
        html += `<tr>
            <td>${b.date} <small>${b.timeStart}</small></td>
            <td>${c ? c.nombre : 'ID:'+b.courtId}</td>
            <td>${b.clientName||'-'}</td>
            <td><span class="badge" style="color:${b.isPaid?'var(--success)':'var(--danger)'}; font-weight:bold; font-size:0.8rem;">${b.isPaid?'PAGADO':'PENDIENTE'}</span></td>
            <td>${b.paymentMethod||'-'}</td>
            <td>$${formatMoney(p)}</td>
        </tr>`;
    });
    tbody.innerHTML = html || '<tr><td colspan="6" style="text-align:center; color:#555; padding:20px;">Sin movimientos</td></tr>';
    
    document.getElementById("kpiTotal").textContent = `$${formatMoney(totEst)}`;
    document.getElementById("kpiPaid").textContent = `$${formatMoney(totPaid)}`;
    document.getElementById("kpiPending").textContent = `$${formatMoney(totPend)}`;
    
    // Barras simples
    const total = (totPaid + totPend) || 1; // evitar division zero
    const pctPending = (totPend/total)*100;
    const pctPaid = (totPaid/total)*100;
    
    const barPend = document.getElementById('barCard'); // Reutilizo IDs de tu HTML original (aunque nombre sea confuso)
    const barPaid = document.getElementById('barCash');
    
    if(barPend) { barPend.style.width = pctPending + '%'; barPend.innerText = pctPending > 10 ? 'Pend' : ''; }
    if(barPaid) { barPaid.style.width = pctPaid + '%'; barPaid.innerText = pctPaid > 10 ? 'Pagado' : ''; }
}

function renderClients() {
    const tbody = document.getElementById('clientsTableBody');
    if(!tbody) return;
    const search = (document.getElementById('clientSearch')?.value || '').toLowerCase();
    
    // Analizar reservas en caché
    const bookings = window.currentBookingsCache || [];
    const clients = {};

    bookings.forEach(b => {
        if(!b.clientPhone) return;
        const p = b.clientPhone.replace(/\D/g,''); // Clave es el teléfono limpio
        
        if(!clients[p]) {
            clients[p] = { 
                name: b.clientName || 'Anónimo', 
                phone: b.clientPhone, 
                count: 0, 
                total: 0 
            };
        }
        
        // Sumar si no está cancelado
        if(b.status !== 'cancelled') {
            clients[p].count++; 
            clients[p].total += (parseInt(b.totalPrice)||0);
        }
    });

    const rows = Object.values(clients)
        .filter(c => c.name.toLowerCase().includes(search) || c.phone.includes(search))
        .sort((a,b) => b.total - a.total); // Top clientes primero

    tbody.innerHTML = rows.map(c => `<tr>
            <td>${c.name}</td>
            <td>${c.phone}</td>
            <td>${c.count}</td>
            <td>$${formatMoney(c.total)}</td>
            <td><a href="https://wa.me/${c.phone.replace(/\D/g,'')}" target="_blank" class="btn-whatsapp"><i class="fa-brands fa-whatsapp"></i> Chat</a></td>
        </tr>`)
        .join('') || '<tr><td colspan="5" style="text-align:center; color:#555; padding:20px;">Sin clientes</td></tr>';
}

function renderAnalytics() {
    const grid = document.getElementById('heatmapGrid');
    if(!grid) return;
    
    const bookings = window.currentBookingsCache || [];
    // Matriz 7 días x 24 horas (o rango)
    const heatmap = Array(7).fill(0).map(() => ({}));
    let maxHeat = 0;

    bookings.forEach(b => {
        if(b.status === 'cancelled') return;
        
        // Parsear fecha para obtener día de la semana (0=Domingo, 6=Sábado)
        // Ojo: split y new Date directo a veces falla por zona horaria. 
        // Truco seguro: crear fecha con hora media 12:00
        const d = new Date(b.date + 'T12:00:00').getDay(); 
        
        // Hora inicio (solo la hora entera)
        const h = parseInt(b.timeStart.split(':')[0]);
        
        if(!heatmap[d][h]) heatmap[d][h] = 0;
        heatmap[d][h]++;
        
        if(heatmap[d][h] > maxHeat) maxHeat = heatmap[d][h];
    });

    // Construir HTML Grid
    let html = `<div class="hm-cell hm-header"></div>`; // Celda vacía esquina
    const dias = ['Dom','Lun','Mar','Mie','Jue','Vie','Sab'];
    dias.forEach(d => html += `<div class="hm-cell hm-header">${d}</div>`);
    
    const sH = parseInt(AppConfig.HORA_INICIO.split(':')[0]);
    const eH = parseInt(AppConfig.HORA_FIN.split(':')[0]);

    for(let h = sH; h <= eH; h++) {
        // Columna hora
        html += `<div class="hm-cell hm-header" style="font-size:0.7rem">${h}:00</div>`;
        
        // Celdas días
        for(let d=0; d<7; d++) {
            const val = heatmap[d][h] || 0;
            // Opacidad basada en calor
            const op = maxHeat > 0 ? (val/maxHeat) : 0;
            const col = val > 0 ? `rgba(0,0,254,${0.2 + op*0.8})` : '#0f0f1a';
            const border = val > 0 ? '1px solid rgba(0,254,254,0.3)' : '1px solid #1a1a2e';
            
            html += `<div class="hm-cell hm-data" style="background:${col}; border:${border}" title="${dias[d]} ${h}:00 - Reservas: ${val}">
                ${val > 0 ? val : ''}
            </div>`;
        }
    }
    grid.innerHTML = html;
}

// --- UTILIDADES ---
function minutesToTime(m) { let am = m % 1440; const h = Math.floor(am / 60).toString().padStart(2, '0'); const mn = (am % 60).toString().padStart(2, '0'); return `${h}:${mn}`; }
function formatMoney(n) { return new Intl.NumberFormat('es-CL').format(n); }