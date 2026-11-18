function ConfirmationDialog({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Keep', busy = false, onConfirm, onCancel }) {
  return <div className="confirmation-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onCancel() }}>
    <section className="confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmation-title">
      <h2 id="confirmation-title">{title}</h2><p>{message}</p>
      <div className="confirmation-actions"><button className="button button-outline" type="button" onClick={onCancel} disabled={busy}>{cancelLabel}</button><button className="button button-primary" type="button" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div>
    </section>
  </div>
}

export default ConfirmationDialog
