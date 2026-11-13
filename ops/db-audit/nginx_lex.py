def nginx_tokens(text):
    """Ignore only unquoted whitespace/comments; preserve quoting and escaped bytes."""
    tokens=[];word='';quote=None;escape=False;comment=False
    for ch in text:
        if comment:
            if ch=='\n':comment=False
            continue
        if escape:word+=ch;escape=False;continue
        if ch=='\\':word+=ch;escape=True;continue
        if quote:
            word+=ch
            if ch==quote:quote=None
            continue
        if ch in {'"',"'"}:word+=ch;quote=ch;continue
        if ch=='#':
            if word:tokens.append(word);word=''
            comment=True;continue
        if ch.isspace() or ch in '{};':
            if word:tokens.append(word);word=''
            if ch in '{};':tokens.append(ch)
        else:word+=ch
    if quote or escape:raise ValueError('UNTERMINATED_NGINX_TOKEN')
    if word:tokens.append(word)
    return tokens
