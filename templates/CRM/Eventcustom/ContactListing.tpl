<div class="crm-block crm-form-block crm-event-group-form-block">
    <table class="selector">
        {assign var="currentUser" value=''}
        {counter start=0 skip=1 print=false}
        {foreach from=$relatedContacts key=contactID item=contact}
            {if $contact.is_parent}
            {assign var="elementName" value=contacts_parent_$contactID}
                {if $contactID eq $currentContactID}
                    {assign var="currentUser" value='currentuser'}
                {/if}
            <tr id='rowid{$contactID}' class="{cycle values="odd-row,even-row"}">
                <td>{$form.$elementName.html} - {$form.$elementName.label}</td>
                <td>{$contact.explanation}</td>
            </tr>
            {/if}
        {/foreach}
        {foreach from=$relatedContacts key=contactID item=contact}
            {if ! $contact.is_parent}
                {assign var="elementName" value=contacts_child_$contactID}
            <tr id='rowid{$contactID}' class="{cycle values="odd-row,even-row"}">
                <td>{$form.$elementName.html} - {$form.$elementName.label}</td>
                <td>{$contact.explanation}</td>
            </tr>
            {/if}
        {/foreach}
    </table>
</div>
{literal}
<script type="text/javascript">
    currentContactID = '{/literal}{$currentContactID}{literal}';
    CRM.$(function($) {
        $('.crm-event-group-form-block').insertAfter('.additional_participants-section');
        $('.additional_participants-section').hide();
    });

    function calculateAdditionalParticipant() {
        var additionalParticiapnt = 0;
        CRM.$('[id^="contacts_"]').each(function(k, v) {
            if(this.checked) {
                var source_id = CRM.$(this).attr('id');
                var source_array = source_id.split('_');
                var p_contact_id = source_array.pop();
                if (p_contact_id != currentContactID) {
                    additionalParticiapnt++;
                }
            }
        });
        console.log('additionalParticiapnt : ' + additionalParticiapnt);
        if (additionalParticiapnt == '0') {
            additionalParticiapnt = '';
        }
        console.log('additionalParticiapnt 2: ' + additionalParticiapnt);
        CRM.$("#additional_participants").val(additionalParticiapnt).trigger("change");
    }

    CRM.$('[id^="contacts_"]').change(function(){
        calculateAdditionalParticipant();
    });
    CRM.$('.currentUser').change(function() {
        eventFeeBlocks();
    });
    eventFeeBlocks();
    function eventFeeBlocks() {
        if (CRM.$(".currentUser:checked").length) {
            CRM.$('fieldset#priceset').show();
            CRM.$('div#priceset').show();
            CRM.$('fieldset.payment_options-group').show();
            CRM.$('div#billing-payment-block').show();
            CRM.$('.payment_processor-section').show();
        }
        else {
            //unset all price values
            CRM.$('div#priceset input').each(function(){
                if (CRM.$(this).prop('type') == 'text') {
                    CRM.$(this).val('').trigger("change"); //text fields
                }
                if (!CRM.$(".currentUser:checked").length) {
                    CRM.$(this).prop('checked', false).trigger("change"); //radio/checkbox
                }
            });

            //select fields
            CRM.$('div#priceset select').each(function(){
                CRM.$(this).val(null).trigger("change");
            });

            //hide price blocks
            CRM.$('fieldset#priceset').hide();
            CRM.$('div#priceset').hide();
            CRM.$('fieldset.payment_options-group').show();
            CRM.$('div#billing-payment-block').show();
            CRM.$('.payment_processor-section').show();

        }
    }
    calculateAdditionalParticipant();
</script>
{/literal}
