// Attach cancellation handlers using SweetAlert if available
(function(){
  async function getCsrf(){
    try { const r = await fetch('check_session.php', { credentials: 'same-origin' }); return (await r.json()).csrf_token; } catch(e){ console.error('CSRF fetch failed', e); return null; }
  }

  async function confirmAndCancel(type, id){
    const Swal = window.Swal || window.swal; // sweetalert2 or sweetalert
    if (!Swal) { if(!confirm('Cancel this '+type+'?')) return; }

    // Build reason selection
    const html = `
      <div>
        <label>Reason</label>
        <select id="cxl_reason" class="swal2-select" style="display:block;width:100%">
          <option>Change of mind</option>
          <option>Wrong order</option>
          <option>Emergency</option>
          <option>Other</option>
        </select>
        <input id="cxl_other" placeholder="Please specify" class="swal2-input" style="display:none" />
      </div>`;

    let reason = 'Change of mind';
    let other = '';

    const result = Swal ? await Swal.fire({
      title: 'Confirm Cancellation',
      html,
      showCancelButton: true,
      confirmButtonText: 'Confirm',
      didOpen: () => {
        const sel = document.getElementById('cxl_reason');
        const oth = document.getElementById('cxl_other');
        sel.addEventListener('change', ()=>{
          reason = sel.value; oth.style.display = reason==='Other'?'block':'none';
        });
      },
      preConfirm: () => {
        const sel = document.getElementById('cxl_reason');
        const oth = document.getElementById('cxl_other');
        reason = sel.value; other = oth.value.trim();
        if (reason==='Other' && !other) { Swal.showValidationMessage('Please specify other reason'); return false; }
        return { reason, other };
      }
    }) : { isConfirmed: true, value: { reason: reason, other: other } };

    if (!result.isConfirmed) return;
    reason = result.value.reason; other = result.value.other;

    const csrf = await getCsrf();
    const fd = new FormData();
    fd.append('type', type);
    fd.append('id', id);
    fd.append('reason', reason);
    if (reason==='Other') fd.append('other_reason_text', other);
    if (csrf) fd.append('csrf_token', csrf);

    let j;
    try {
      const res = await fetch('api/cancel_request.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      j = await res.json();
    } catch (e) {
      console.error('Cancel request failed', e);
      if (Swal) Swal.fire('Error', 'Network or server error. Please try again.', 'error'); else alert('Network or server error');
      return;
    }

    if (!j.success) {
      Swal ? Swal.fire('Error', j.error||'Unable to cancel', 'error') : alert(j.error||'Unable to cancel');
    } else {
      const refundAmount = Number(j.refund_amount || 0);
      const baseMessage = String(j.message || 'Your cancellation has been submitted.');
      const finalMessage = refundAmount > 0
        ? `${baseMessage} Refund amount: PHP ${refundAmount.toFixed(2)}.`
        : baseMessage;
      Swal ? Swal.fire('Cancelled', finalMessage, 'success') : alert(finalMessage);
      // Optionally reload or update UI
      setTimeout(()=>{ location.reload(); }, 1200);
    }
  }

  // Event delegation: buttons having data-cancel-type and data-id
  document.addEventListener('click', (e)=>{
    const el = e.target.closest('[data-cancel-type][data-id]');
    if (!el) return;
    e.preventDefault();
    const type = el.getAttribute('data-cancel-type');
    const id = el.getAttribute('data-id');
    confirmAndCancel(type, id);
  });
})();
