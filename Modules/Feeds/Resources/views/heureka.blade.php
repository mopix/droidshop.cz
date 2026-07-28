<?xml version="1.0" encoding="UTF-8"?>
<SHOP>
@foreach ($items as $item)
    <SHOPITEM>
        <ITEM_ID>{{ $item->id }}</ITEM_ID>
        <PRODUCTNAME>{{ $item->name }}</PRODUCTNAME>
        <DESCRIPTION>{{ $item->description }}</DESCRIPTION>
        <URL>{{ $item->url }}</URL>
@if ($item->imageUrl)
        <IMGURL>{{ $item->imageUrl }}</IMGURL>
@endif
@foreach ($item->alternativeImageUrls as $alternative)
        <IMGURL_ALTERNATIVE>{{ $alternative }}</IMGURL_ALTERNATIVE>
@endforeach
        <PRICE_VAT>{{ number_format($item->priceVat->amount / 100, 2, '.', '') }}</PRICE_VAT>
@if ($item->manufacturer)
        <MANUFACTURER>{{ $item->manufacturer }}</MANUFACTURER>
@endif
@if ($item->categoryText !== '')
        <CATEGORYTEXT>{{ $item->categoryText }}</CATEGORYTEXT>
@endif
@if ($item->ean)
        <EAN>{{ $item->ean }}</EAN>
@endif
@if ($item->sku)
        <PRODUCTNO>{{ $item->sku }}</PRODUCTNO>
@endif
        <DELIVERY_DATE>{{ $item->deliveryDays }}</DELIVERY_DATE>
@if ($item->itemGroupId)
        <ITEMGROUP_ID>{{ $item->itemGroupId }}</ITEMGROUP_ID>
@endif
@foreach ($item->params as $name => $value)
        <PARAM>
            <PARAM_NAME>{{ $name }}</PARAM_NAME>
            <VAL>{{ $value }}</VAL>
        </PARAM>
@endforeach
@foreach ($shipping as $option)
        <DELIVERY>
            <DELIVERY_ID>{{ $option->name() }}</DELIVERY_ID>
            <DELIVERY_PRICE>{{ number_format($option->price()->amount / 100, 2, '.', '') }}</DELIVERY_PRICE>
        </DELIVERY>
@endforeach
    </SHOPITEM>
@endforeach
</SHOP>
