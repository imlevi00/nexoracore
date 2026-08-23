-- دەستکاریکردنی وەسڵ (pos_receipt_edit) هەمیشە چالاکە لە کۆددا؛ ئەم migration ـە تۆمارە کۆنەکان پاک دەکاتەوە.
UPDATE package_feature_permissions SET is_enabled = 1 WHERE feature_key = 'pos_receipt_edit';
