// --- CONFIGURACIÓN ---
let AppConfig = window.SERVER_CONFIG || {};
let selectedCourtId = null;
let selectedSlot = null;
let currentBookingExtras = {}; 
let splitCount = 1;
let currentDiscount = 0;
let currentCouponCode = null;
const TODAY_ISO = new Date().toISOString().split('T')[0];
const SYSTEM_SLUG = AppConfig.SLUG || 'umm2';

// --- INICIALIZACIÓN ---
document.addEventListener('DOMContentLoaded', initApp);

async function initApp() {
    // Validar configuración
    if (!AppConfig.CANCHAS || AppConfig.CANCHAS.length === 0) {
        document.getElementById('courtSelector').innerHTML = '<div style="color:white;padding:20px">Sistema no configurado.</div>';
        return;
    }

    renderCourtSelector();
    // Seleccionar primera cancha
    selectCourt(AppConfig.CANCHAS[0].id);
    document.getElementById('currentDateDisplay').textContent = TODAY_ISO;
    setupEventListeners();
}

// --- API FETCH HELPER ---
async function apiCall(url, method = 'GET', body = null) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const options = {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }
    };
    if (body) options.body = JSON.stringify(body);
    
    const res = await fetch(url, options);
    if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error || 'Error en el servidor');
    }
    return await res.json();
}

// --- RENDERIZADO ---
function renderCourtSelector() {
    const nav = document.getElementById('courtSelector');
    nav.innerHTML = AppConfig.CANCHAS.map(c => 
        `<button class="court-tab" onclick="selectCourt(${c.id})" data-id="${c.id}">${c.nombre}</button>`
    ).join('');
}

async function selectCourt(id) {
    selectedCourtId = id;
    document.querySelectorAll('.court-tab').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.id) === id);
    });

    const court = AppConfig.CANCHAS.find(c => c.id === id);
    if (!court) return;

    // Render detalles visuales (amenidades)
    const amenityObjects = (court.amenidades || []).map(aId => 
        (AppConfig.AMENIDADES || []).find(a => a.id === aId)
    ).filter(Boolean);
    
    document.getElementById('courtDetails').innerHTML = `
        <div class="court-card">
            <img src="${court.imagen}" class="court-img" alt="${court.nombre}">
            <div class="court-info">
                <h2>${court.nombre}</h2>
                <div class="amenities-list">
                    ${amenityObjects.map(a => `<span class="amenity-badge"><i class="fa-solid ${a.icon}"></i> ${a.nombre}</span>`).join('')}
                </div>
            </div>
        </div>`;
    
    // AHORA: Generar slots requiere esperar a la BD
    await generateSlots(court);
}

async function generateSlots(court) {
    const container = document.getElementById('slotsContainer');
    container.innerHTML = '<div style="color:white;padding:20px">Cargando disponibilidad...</div>';
    
    // 1. OBTENER RESERVAS DE LA BD
    let bookings = [];
    try {
        bookings = await apiCall(`/api/bookings?slug=${SYSTEM_SLUG}&date=${TODAY_ISO}`);
    } catch (e) {
        container.innerHTML = '<div style="color:red;padding:20px">Error cargando horarios.</div>';
        return;
    }

    container.innerHTML = ''; // Limpiar
    
    const startMins = timeToMinutes(AppConfig.HORA_INICIO);
    let endMins = timeToMinutes(AppConfig.HORA_FIN);
    const interval = parseInt(AppConfig.INTERVALO_MIN);
    if (endMins <= startMins) endMins += 1440;

    const courtBookings = bookings.filter(b => parseInt(b.courtId) === selectedCourtId);
    let current = startMins;
    
    while (current + interval <= endMins) {
        const startStr = minutesToTime(current);
        const endStr = minutesToTime(current + interval);
        
        // Verificar ocupación
        const isOccupied = courtBookings.some(b => b.timeStart === startStr && b.status !== 'cancelled');
        const price = court.precio || AppConfig.PRECIO_BASE;

        const slotEl = document.createElement('div');
        slotEl.className = `slot-card ${isOccupied ? 'occupied' : ''}`;
        slotEl.innerHTML = `
            <div>
                <div><i class="fa-regular fa-clock"></i> ${startStr} - ${endStr}</div>
                <small>${isOccupied ? 'No disponible' : 'Disponible'}</small>
            </div>
            <div class="slot-price">$${formatMoney(price)}</div>
        `;

        if (!isOccupied) {
            slotEl.onclick = () => openBookingModal(startStr, endStr, price);
        }
        container.appendChild(slotEl);
        current += interval;
    }
}

// --- MODALES ---
const modalBooking = document.getElementById('modalBooking');
const modalPayment = document.getElementById('modalPayment');

function openBookingModal(start, end, price) {
    selectedSlot = { start, end, price };
    const court = AppConfig.CANCHAS.find(c => c.id === selectedCourtId);
    document.getElementById('bookingSummary').innerHTML = `
        <p><strong>Cancha:</strong> ${court.nombre}</p>
        <p><strong>Horario:</strong> ${start} - ${end}</p>
        <p><strong>Precio Base:</strong> $${formatMoney(price)}</p>`;
    
    // Reset inputs
    document.getElementById('userName').value = '';
    document.getElementById('userPhone').value = '';
    document.getElementById('userEmail').value = '';
    modalBooking.classList.add('active');
}

function setupEventListeners() {
    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.onclick = () => { modalBooking.classList.remove('active'); modalPayment.classList.remove('active'); };
    });
    document.getElementById('btnProceedToExtras').onclick = () => {
        const name = document.getElementById('userName').value.trim();
        const phone = document.getElementById('userPhone').value.trim();
        if (!name || !phone) { alert("Ingresa nombre y teléfono"); return; }
        modalBooking.classList.remove('active');
        preparePaymentModal();
        modalPayment.classList.add('active');
    };
    document.getElementById('btnLessPeople').onclick = () => updateSplit(-1);
    document.getElementById('btnMorePeople').onclick = () => updateSplit(1);
    document.getElementById('btnConfirmPayment').onclick = processPayment;
    document.querySelectorAll('.btn-method').forEach(btn => {
        btn.onclick = function() {
            document.querySelectorAll('.btn-method').forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
        }
    });
}

function preparePaymentModal() {
    currentBookingExtras = {};
    splitCount = 1;
    currentDiscount = 0;
    currentCouponCode = null;
    document.getElementById('couponInput').value = '';
    document.getElementById('couponMsg').textContent = '';
    updateSplit(0);
    renderExtrasList();
    calculateTotal();
}

window.applyCoupon = function() {
    const code = document.getElementById('couponInput').value.toUpperCase().trim();
    // (Lógica de cupones se mantiene local por ahora hasta migrar tabla cupones)
    // ...
};

function renderExtrasList() {
    // ... (Misma lógica visual de extras) ...
    // Para brevedad, asumo que mantienes esta función igual
    const container = document.getElementById('extrasList');
    container.innerHTML = (AppConfig.EXTRAS||[]).map(ex => `
        <div class="extra-item">
            <div><div>${ex.nombre}</div><small>$${formatMoney(ex.precio)}</small></div>
            <div class="qty-control">
                <button onclick="updateExtra('${ex.id}', -1, ${ex.precio})">-</button>
                <span id="qty-${ex.id}">0</span>
                <button onclick="updateExtra('${ex.id}', 1, ${ex.precio})">+</button>
            </div>
        </div>`).join('');
}

window.updateExtra = function(id, delta, price) {
    if (!currentBookingExtras[id]) currentBookingExtras[id] = 0;
    const newQty = currentBookingExtras[id] + delta;
    if (newQty >= 0) {
        currentBookingExtras[id] = newQty;
        document.getElementById(`qty-${id}`).textContent = newQty;
        calculateTotal();
    }
};

function updateSplit(delta) {
    const newSplit = splitCount + delta;
    if (newSplit >= 1 && newSplit <= (AppConfig.MAX_DIV_PAGO || 6)) {
        splitCount = newSplit;
        document.getElementById('peopleCount').textContent = `${splitCount} persona${splitCount>1?'s':''}`;
        calculateTotal();
    }
}

function calculateTotal() {
    let totalExtras = 0;
    for (const [id, qty] of Object.entries(currentBookingExtras)) {
        const extra = AppConfig.EXTRAS.find(e => e.id === id);
        if(extra) totalExtras += (extra.precio * qty);
    }
    let grandTotal = selectedSlot.price + totalExtras;
    // Descuentos...
    const perPerson = Math.ceil(grandTotal / splitCount);
    document.getElementById('finalTotal').textContent = `$${formatMoney(grandTotal)}`;
    document.getElementById('pricePerPerson').textContent = `$${formatMoney(perPerson)}`;
}

async function processPayment() {
    const btn = document.getElementById('btnConfirmPayment');
    btn.disabled = true; btn.textContent = "Procesando...";

    const cName = document.getElementById('userName').value.trim();
    const cPhone = document.getElementById('userPhone').value.trim();
    const cEmail = document.getElementById('userEmail').value.trim();
    const selectedMethodBtn = document.querySelector('.btn-method.selected');
    const paymentMethod = selectedMethodBtn ? (selectedMethodBtn.innerText.includes('Efectivo')?'Efectivo':'Tarjeta') : 'Tarjeta';
    
    const totalPrice = parseInt(document.getElementById('finalTotal').textContent.replace(/\D/g,''));

    const bookingData = {
        courtId: selectedCourtId,
        date: TODAY_ISO,
        timeStart: selectedSlot.start,
        timeEnd: selectedSlot.end,
        status: 'booked',
        totalPrice: totalPrice,
        isPaid: false,
        paymentMethod: paymentMethod,
        clientName: cName,
        clientPhone: cPhone,
        clientEmail: cEmail,
        extras: currentBookingExtras,
        split: splitCount,
        adminCreated: false
    };

    try {
        // ENVIAR A BD
        await apiCall('/api/bookings', 'POST', bookingData);
        alert("¡Reserva confirmada en Base de Datos!");
        modalPayment.classList.remove('active');
        // Recargar slots para ver el bloqueo
        selectCourt(selectedCourtId);
    } catch (e) {
        alert("Error al reservar: " + e.message);
    } finally {
        btn.disabled = false; btn.textContent = "Confirmar Reserva";
    }
}

// --- UTILIDADES ---
function timeToMinutes(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }
function minutesToTime(m) { let am = m % 1440; const h = Math.floor(am / 60).toString().padStart(2, '0'); const mn = (am % 60).toString().padStart(2, '0'); return `${h}:${mn}`; }
function formatMoney(n) { return new Intl.NumberFormat('es-CL').format(n); }