<div class="crm-content-block crm-block">
  <div id="help">
    {ts}Overzicht van geselecteerde facturen voor Exact{/ts}
  </div>
  <h3>{ts}Onderstaande facturen worden naar Exact verzonden als u de Bevestig knop klikt{/ts}</h3>
  <div id="bemas_invoices_to_exact_correct_page_wrapper" class="dataTables_wrapper">
    <table id="bemas_invoices_to_exact_correct-table" class="display">
      <thead>
        <tr>
          <th class="sorting-disabled" rowspan="1" colspan="1">{ts}Contact{/ts}</th>
          <th class="sorting-disabled" rowspan="1" colspan="1">{ts}Gegevens{/ts}</th>
        </tr>
      </thead>
      <tbody>
      {foreach from=$correctElements key=correctId item=correct}
        {assign var=correctContact value=$correct.contact}
        {assign var=correctData value=$correct.data}
        <tr id="row_{$correctId}" class="{cycle values="odd-row,even-row"}">
          <td>{$form.$correctContact.value}</td>
          <td>{$form.$correctData.value}</td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  </div>
  <div id="bemas_invoicestoexact_footer_buttons" class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
  {if !empty($errorElements)}
    <h3>{ts}Onderstaande facturen kunnen NIET naar Exact verzonden worden{/ts}</h3>
    <div id="bemas_invoices_to_exact_error_page_wrapper" class="dataTables_wrapper">
      <table id="bemas_invoices_to_exact_error-table" class="display">
        <thead>
          <tr>
            <th class="sorting-disabled" rowspan="1" colspan="1">{ts}Contact{/ts}</th>
            <th class="sorting-disabled" rowspan="1" colspan="1">{ts}Foutmelding{/ts}</th>
          </tr>
        </thead>
        <tbody>
        {foreach from=$errorElements key=errorId item=error}
          {assign var=errorContact value=$error.contact}
          {assign var=errorMessage value=$error.message}
          <tr id="row_{$errorId}" class="{cycle values="odd-row,even-row"}">
            <td>{$form.$errorContact.value}</td>
            <td>{$form.$errorMessage.value}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  {/if}

</div>