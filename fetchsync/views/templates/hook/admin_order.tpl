{*
 * Fetch block on the order page (displayAdminOrderSide).
 *}
<div class="card mt-2" id="fetchsync-order">
  <div class="card-header">
    <h3 class="card-header-title">{l s='Fetch' mod='fetchsync'}</h3>
  </div>
  <div class="card-body">
    {if $fetchsync_notice}
      <div class="alert alert-{$fetchsync_notice.type|escape:'html':'UTF-8'}" role="alert">
        <p class="alert-text">{$fetchsync_notice.message|escape:'html':'UTF-8'}</p>
      </div>
    {/if}
    <p>
      <strong>{l s='Fetch order' mod='fetchsync'}:</strong> {$fetchsync_number|escape:'html':'UTF-8'}<br>
      <strong>{l s='Status' mod='fetchsync'}:</strong> {$fetchsync_label|escape:'html':'UTF-8'}
    </p>
    {if !$fetchsync_can_accept && !$fetchsync_can_ready && !$fetchsync_can_reject}
      <p class="text-muted mb-0">{l s='Nothing to do here — Fetch handles the delivery from this point.' mod='fetchsync'}</p>
    {else}
      <form method="post" action="{$fetchsync_url|escape:'html':'UTF-8'}" id="fetchsync-order-form">
        <input type="hidden" name="fetchsync_order" value="{$fetchsync_id_order|intval}">
        {if $fetchsync_can_accept}
          <div class="form-group">
            <label for="fetchsync_prep_minutes">{l s='Ready in (minutes)' mod='fetchsync'}</label>
            <input type="number" class="form-control" id="fetchsync_prep_minutes" name="fetchsync_prep_minutes" min="0" max="180" value="{$fetchsync_prep_minutes|intval}">
          </div>
          <p><button type="submit" class="btn btn-primary" name="fetchsync_action" value="accept">{l s='Accept order' mod='fetchsync'}</button></p>
        {/if}
        {if $fetchsync_can_ready}
          <p><button type="submit" class="btn btn-primary" name="fetchsync_action" value="ready">{l s='Mark ready for pickup' mod='fetchsync'}</button></p>
        {/if}
        {if $fetchsync_can_reject}
          <hr>
          <div class="form-group">
            <select name="fetchsync_reason" class="form-control">
              {foreach from=$fetchsync_reasons key=code item=label}
                <option value="{$code|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
          </div>
          <div class="form-group">
            <input type="text" class="form-control" name="fetchsync_note" maxlength="300" placeholder="{l s='Note (optional)' mod='fetchsync'}">
          </div>
          <p class="mb-0"><button type="submit" class="btn btn-outline-danger" name="fetchsync_action" value="reject" onclick="return confirm('{l s='Reject this Fetch order? The customer is refunded.' mod='fetchsync' js=1}');">{l s='Reject order' mod='fetchsync'}</button></p>
        {/if}
      </form>
    {/if}
  </div>
</div>
